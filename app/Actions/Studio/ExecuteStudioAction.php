<?php

namespace App\Actions\Studio;

use App\Actions\Moderation\ApprovePostAction;
use App\Actions\Posts\CreatePostAction;
use App\Actions\Ranking\RecalculatePostScoreAction;
use App\Data\Posts\CreatePostData;
use App\Enums\StudioActionFailure;
use App\Enums\StudioActionStatus;
use App\Enums\StudioActionType;
use App\Enums\StudioPlanStatus;
use App\Enums\UserStatus;
use App\Exceptions\Abuse\RateLimitExceededException;
use App\Exceptions\Moderation\CannotModeratePostException;
use App\Exceptions\Posts\CannotCreatePostException;
use App\Exceptions\Studio\StudioActionStoppedException;
use App\Models\Concerns\LocksUsersInOrder;
use App\Models\Post;
use App\Models\StudioAction;
use App\Models\StudioPlan;
use App\Models\User;
use App\Support\Observability\DomainLogger;
use App\Support\Studio\StudioExecutionSwitch;
use Carbon\CarbonImmutable;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\DetectsLostConnections;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Runs one sent action of an approved plan — today its CreatePost — and
 * applies it once (docs/architecture/studio-plans.md, "Running a plan"),
 * however often its job is delivered. Called by ExecuteStudioActionJob only.
 *
 * Two transactions:
 *
 * - The claim, committed on its own: the action, still Dispatched and due, is
 *   locked and becomes Running with `claimed_at`; its plan, if still Approved,
 *   Running too. The attempt it was sent with is remembered; nothing counts
 *   it again. A redelivered job, or one for an action already done, cancelled
 *   or sent again, claims nothing and does nothing.
 * - The run, one transaction for all of it: the action locked again and found
 *   still Running at the claimed attempt — else recovery has sent it again
 *   and this run writes nothing — then the Persona's and the approving
 *   administrator's `users` rows in ascending id order, then the plan. The
 *   post is written through the site's own actions under their own checks
 *   and locks: CreatePostAction with the plan's photo as it is stored and the
 *   plan's date as the post's own, ApprovePostAction as the administrator who
 *   approved the plan, published on that date, and RecalculatePostScoreAction
 *   under the post's lock — none of them touching the post's `updated_at`
 *   (Post::withoutTimestamps()). The action becomes Done with its post and the
 *   real `finished_at`, and the plan Completed once nothing of it is left to
 *   run. Either all of it is committed, or none of it is.
 *
 * What stops a run is recorded only after the run is taken back, by a short
 * transaction of its own that writes only to the attempt it claimed: a
 * Limited or Shadowbanned Persona holds the action, a banned, deleted or
 * non-Persona author or something the post can no longer use cancels it, an
 * administrator who may no longer approve or anything unexpected fails it,
 * and a Persona at its posting limit sends it back to Pending, due once the
 * limit allows. A lost connection or a deadlock is no verdict: the action
 * stays Running and recovery sends it again.
 *
 * Nothing runs while the executor is switched off (StudioExecutionSwitch).
 */
final class ExecuteStudioAction
{
    use DetectsConcurrencyErrors;
    use DetectsLostConnections;
    use LocksUsersInOrder;

    /** The kinds of action that run. The others wait for the stage that runs them. */
    public const RUNNABLE = [StudioActionType::CreatePost];

    public function __construct(
        private readonly CreatePostAction $createPost,
        private readonly ApprovePostAction $approvePost,
        private readonly RecalculatePostScoreAction $recalculateScore,
        private readonly StudioExecutionSwitch $switch,
        private readonly DomainLogger $logger,
    ) {}

    /**
     * @return StudioActionStatus|null what the action became, or null when this call did nothing to it
     */
    public function handle(int $actionId): ?StudioActionStatus
    {
        if (! $this->switch->isOn()) {
            $this->logger->info('studio.action.skipped', ['studio_action_id' => $actionId, 'reason' => 'execution_disabled']);

            return null;
        }

        $attempt = $this->claim($actionId);

        if ($attempt === null) {
            $this->logger->info('studio.action.skipped', ['studio_action_id' => $actionId, 'reason' => 'not_claimable']);

            return null;
        }

        try {
            $post = DB::transaction(fn (): ?Post => $this->run($actionId, $attempt));
        } catch (StudioActionStoppedException $stop) {
            return $this->stop($actionId, $attempt, $stop->failure);
        } catch (RateLimitExceededException $limit) {
            return $this->postpone($actionId, $attempt, $limit->retryAfterSeconds());
        } catch (Throwable $exception) {
            // Committed already: what failed came after the commit, such as a
            // job for followers the queue could not take.
            if ($this->isDone($actionId, $attempt)) {
                report($exception);
                $this->logDone($actionId);

                return StudioActionStatus::Done;
            }

            // No verdict on the action: the run is taken back, the action stays
            // Running, and recovery sends it again.
            if ($this->causedByLostConnection($exception) || $this->causedByConcurrencyError($exception)) {
                throw $exception;
            }

            return $this->stop($actionId, $attempt, self::failureOf($exception), $exception);
        }

        if ($post === null) {
            $this->logger->info('studio.action.discarded', ['studio_action_id' => $actionId, 'attempt' => $attempt]);

            return null;
        }

        $this->logDone($actionId);

        return StudioActionStatus::Done;
    }

    /**
     * @return int|null the attempt claimed, or null when there was nothing to claim
     */
    private function claim(int $actionId): ?int
    {
        return DB::transaction(function () use ($actionId): ?int {
            $action = StudioAction::query()->whereKey($actionId)->lockForUpdate()->first();

            if ($action === null
                || $action->status !== StudioActionStatus::Dispatched
                || ! in_array($action->type, self::RUNNABLE, true)
                || $action->run_after->isFuture()) {
                return null;
            }

            $plan = StudioPlan::query()->whereKey($action->studio_plan_id)->lockForUpdate()->first();

            if ($plan !== null && $plan->status === StudioPlanStatus::Approved) {
                self::saved($plan->forceFill(['status' => StudioPlanStatus::Running]));
            }

            self::saved($action->forceFill(['status' => StudioActionStatus::Running, 'claimed_at' => now()]));

            return $action->attempts;
        });
    }

    /** The post written, approved and scored, and the action done — or null when the attempt is no longer this run's. */
    private function run(int $actionId, int $attempt): ?Post
    {
        $action = StudioAction::query()->whereKey($actionId)->lockForUpdate()->first();

        if ($action === null || $action->status !== StudioActionStatus::Running || $action->attempts !== $attempt) {
            return null;
        }

        // Who approved the plan is read before any user is locked, so both
        // users are locked before the plan, as every write takes its locks.
        $approverId = StudioPlan::query()->whereKey($action->studio_plan_id)->value('approved_by_user_id');
        $approverId = $approverId !== null ? (int) $approverId : null;

        $users = $this->lockUsersInOrder($action->actor_user_id, ...($approverId !== null ? [$approverId] : []));
        $plan = StudioPlan::query()->whereKey($action->studio_plan_id)->lockForUpdate()->first();

        if ($plan === null
            || $plan->status !== StudioPlanStatus::Running
            || $plan->author_user_id !== $action->actor_user_id
            || $plan->approved_by_user_id !== $approverId) {
            throw new StudioActionStoppedException(StudioActionFailure::PlanUnavailable);
        }

        if ($plan->image_asset_id === null) {
            throw new StudioActionStoppedException(StudioActionFailure::PhotoUnavailable);
        }

        $persona = self::runnablePersona($users->get($action->actor_user_id));
        $approver = $approverId !== null ? $users->get($approverId) : null;

        if ($approver === null) {
            throw new StudioActionStoppedException(StudioActionFailure::ApproverNotAllowed);
        }

        // The post's own dates are the plan's, however late it runs, and
        // nothing this run writes to the post afterwards moves them.
        $post = Post::withoutTimestamps(function () use ($action, $plan, $persona, $approver): Post {
            $post = $this->createPost->handle($persona, new CreatePostData(
                title: (string) $plan->title,
                description: $plan->description,
                sourceUrl: $plan->source_url,
                tagIds: $plan->tag_ids,
                categoryId: $plan->category_id,
                authorAnswerOptionIds: $plan->author_answer_option_ids,
                imageAssetId: $plan->image_asset_id,
                createdAt: $action->occurs_at,
                updatedAt: $action->occurs_at,
            ));

            $this->approvePost->handle($approver, $post, null, $action->occurs_at);
            $this->recalculateScore->handle($post);

            return $post;
        });

        self::saved($action->forceFill([
            'status' => StudioActionStatus::Done,
            'result_type' => $post->getMorphClass(),
            'result_id' => $post->id,
            'finished_at' => now(),
            'failure_code' => null,
            'failure_reason' => null,
        ]));

        $this->settlePlan($plan);

        return $post;
    }

    /** The author, when it may publish now; otherwise why it may not. */
    private static function runnablePersona(?User $author): User
    {
        if ($author === null) {
            throw new StudioActionStoppedException(StudioActionFailure::AuthorNotPersona);
        }

        $failure = match (true) {
            $author->isTombstoned() => StudioActionFailure::AuthorDeleted,
            ! $author->isPersona() => StudioActionFailure::AuthorNotPersona,
            $author->status === UserStatus::Banned => StudioActionFailure::AuthorBanned,
            // Limited or Shadowbanned: held until an administrator resumes it.
            ! $author->canCreateContent() => StudioActionFailure::AuthorRestricted,
            default => null,
        };

        if ($failure !== null) {
            throw new StudioActionStoppedException($failure);
        }

        return $author;
    }

    /**
     * The plan, once nothing of it is left to run or to decide: Completed when
     * its post was published, Cancelled when it never will be. A held or failed
     * action still waits for an administrator, and keeps the plan Running.
     */
    private function settlePlan(StudioPlan $plan): void
    {
        $open = StudioAction::query()
            ->where('studio_plan_id', $plan->id)
            ->whereIn('status', [StudioActionStatus::Pending, StudioActionStatus::Held, StudioActionStatus::Dispatched, StudioActionStatus::Running, StudioActionStatus::Failed])
            ->exists();

        if ($open) {
            return;
        }

        $published = StudioAction::query()
            ->where('studio_plan_id', $plan->id)
            ->where('type', StudioActionType::CreatePost)
            ->where('status', StudioActionStatus::Done)
            ->exists();

        self::saved($plan->forceFill(['status' => $published ? StudioPlanStatus::Completed : StudioPlanStatus::Cancelled]));
    }

    /** Records why the run stopped, on the attempt it claimed only. */
    private function stop(int $actionId, int $attempt, StudioActionFailure $failure, ?Throwable $cause = null): ?StudioActionStatus
    {
        $status = $failure->status();

        $stopped = DB::transaction(function () use ($actionId, $attempt, $failure, $status, $cause): bool {
            $action = StudioAction::query()->whereKey($actionId)->lockForUpdate()->first();

            if ($action === null || $action->status !== StudioActionStatus::Running || $action->attempts !== $attempt) {
                return false;
            }

            self::saved($action->forceFill([
                'status' => $status,
                'failure_code' => $failure->value,
                // Its class alone: a message may carry what the post says.
                'failure_reason' => $cause !== null ? $cause::class : null,
                'finished_at' => $status === StudioActionStatus::Held ? null : now(),
            ]));

            if ($status === StudioActionStatus::Cancelled) {
                $plan = StudioPlan::query()->whereKey($action->studio_plan_id)->lockForUpdate()->first();

                if ($plan !== null) {
                    $this->settlePlan($plan);
                }
            }

            return true;
        });

        if (! $stopped) {
            $this->logger->info('studio.action.discarded', ['studio_action_id' => $actionId, 'attempt' => $attempt]);

            return null;
        }

        $this->logger->warning('studio.action.'.$status->value, [
            'studio_action_id' => $actionId,
            'attempt' => $attempt,
            'status' => $status->value,
            'failure_code' => $failure->value,
        ]);

        return $status;
    }

    /**
     * Back to Pending, due once the Persona's posting limit allows: a limit
     * reached is no verdict on the post. Its date stays the plan's, and the
     * next send counts as an attempt, so the retries are bounded too.
     */
    private function postpone(int $actionId, int $attempt, int $retryAfterSeconds): ?StudioActionStatus
    {
        $runAfter = DB::transaction(function () use ($actionId, $attempt, $retryAfterSeconds): ?CarbonImmutable {
            $action = StudioAction::query()->whereKey($actionId)->lockForUpdate()->first();

            if ($action === null || $action->status !== StudioActionStatus::Running || $action->attempts !== $attempt) {
                return null;
            }

            $runAfter = CarbonImmutable::now()->addSeconds(max(1, $retryAfterSeconds))->startOfSecond();
            $runAfter = $runAfter->greaterThan($action->run_after) ? $runAfter : $action->run_after;

            self::saved($action->forceFill([
                'status' => StudioActionStatus::Pending,
                'run_after' => $runAfter,
                'claimed_at' => null,
                'failure_code' => StudioActionFailure::RateLimited->value,
                'failure_reason' => null,
            ]));

            return $runAfter;
        });

        if ($runAfter === null) {
            $this->logger->info('studio.action.discarded', ['studio_action_id' => $actionId, 'attempt' => $attempt]);

            return null;
        }

        $this->logger->info('studio.action.postponed', [
            'studio_action_id' => $actionId,
            'attempt' => $attempt,
            'failure_code' => StudioActionFailure::RateLimited->value,
            'run_after' => $runAfter->toIso8601String(),
        ]);

        return StudioActionStatus::Pending;
    }

    private function isDone(int $actionId, int $attempt): bool
    {
        return StudioAction::query()
            ->whereKey($actionId)
            ->where('status', StudioActionStatus::Done)
            ->where('attempts', $attempt)
            ->exists();
    }

    private function logDone(int $actionId): void
    {
        $action = StudioAction::query()->find($actionId);

        $this->logger->info('studio.action.done', [
            'studio_action_id' => $actionId,
            'studio_plan_id' => $action?->studio_plan_id,
            'persona_id' => $action?->actor_user_id,
            'type' => $action?->type->value,
            'status' => $action?->status->value,
            'attempt' => $action?->attempts,
            'result_id' => $action?->result_id,
            'occurs_at' => $action?->occurs_at->toIso8601String(),
            'finished_at' => $action?->finished_at?->toIso8601String(),
        ]);
    }

    /** What an exception out of the run says of the action. */
    private static function failureOf(Throwable $exception): StudioActionFailure
    {
        return match (true) {
            $exception instanceof CannotCreatePostException && $exception->isImageUnavailable() => StudioActionFailure::PhotoUnavailable,
            $exception instanceof CannotCreatePostException && $exception->isCategoryInvalid() => StudioActionFailure::CategoryUnavailable,
            $exception instanceof CannotCreatePostException && $exception->isAuthorAnswerInvalid() => StudioActionFailure::AnswerUnavailable,
            $exception instanceof CannotCreatePostException && $exception->isNotAllowed() => StudioActionFailure::AuthorRestricted,
            $exception instanceof CannotModeratePostException && $exception->isNotAllowed() => StudioActionFailure::ApproverNotAllowed,
            default => StudioActionFailure::UnexpectedFailure,
        };
    }

    /**
     * A save a model event cancels returns false rather than throwing; here
     * it fails the whole transaction, as an exception would.
     */
    private static function saved(Model $model): void
    {
        if (! $model->save()) {
            throw new RuntimeException('A write of the studio action\'s run was not saved: '.$model::class.'.');
        }
    }
}
