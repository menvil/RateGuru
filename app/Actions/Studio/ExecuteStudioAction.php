<?php

namespace App\Actions\Studio;

use App\Actions\Comments\AddCommentAction;
use App\Actions\Moderation\ApprovePostAction;
use App\Actions\Posts\CreatePostAction;
use App\Actions\Ranking\RecalculatePostScoreAction;
use App\Actions\Studio\Concerns\CancelsDependentStudioActions;
use App\Data\Posts\CreatePostData;
use App\Enums\CommentStatus;
use App\Enums\StudioActionFailure;
use App\Enums\StudioActionStatus;
use App\Enums\StudioActionType;
use App\Enums\StudioPlanStatus;
use App\Enums\UserStatus;
use App\Exceptions\Abuse\RateLimitExceededException;
use App\Exceptions\Comments\CannotCommentException;
use App\Exceptions\Moderation\CannotModeratePostException;
use App\Exceptions\Posts\CannotCreatePostException;
use App\Exceptions\Studio\StudioActionStoppedException;
use App\Models\Comment;
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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Runs one sent action of an approved plan — its CreatePost, or a comment or
 * reply of its discussion — and applies it once
 * (docs/architecture/studio-plans.md, "Running a plan"), however often its
 * job is delivered. Called by ExecuteStudioActionJob only.
 *
 * Two transactions:
 *
 * - The claim, committed on its own: the action, still Dispatched and due, is
 *   locked and becomes Running with `claimed_at`; its plan, if still Approved,
 *   Running too. The attempt it was sent with is remembered; nothing counts
 *   it again. A redelivered job, or one for an action already done, cancelled
 *   or sent again, claims nothing and does nothing. An action whose parent is
 *   not done is never claimed: it goes back to Pending to wait for it
 *   (`parent_not_done`), or, its parent cancelled for good, is cancelled with
 *   its own branch (`parent_cancelled`).
 * - The run, one transaction for all of it: the action locked again and found
 *   still Running at the claimed attempt — else recovery has sent it again
 *   and this run writes nothing — then the `users` rows the write touches in
 *   ascending id order, then the plan, then what the write is on. Everything
 *   is written through the site's own actions under their own checks and
 *   locks, never around them, and nothing it writes afterwards moves the
 *   post's `updated_at` (Post::withoutTimestamps()):
 *   - a post: the Persona's and the approving administrator's rows, the plan,
 *     the photo, the post — CreatePostAction with the plan's photo as it is
 *     stored and the plan's date as the post's own, ApprovePostAction as the
 *     administrator who approved the plan, published on that date, and
 *     RecalculatePostScoreAction under the post's lock;
 *   - a comment or a reply: its parent asked again, done, and what it wrote
 *     read; the commenting Persona's and the post's author's rows — the post's
 *     author is who PostCommentedNotification goes to — the plan, the post,
 *     and for a reply the comment it answers; then AddCommentAction, its own
 *     locks all held already, with the action's date as the comment's
 *     `created_at` and `updated_at` (Comment::withoutTimestamps() too).
 *   The action becomes Done with what it wrote and the real `finished_at`,
 *   and the plan Completed once nothing of it is left to run or decide.
 *   Either all of it is committed, or none of it is.
 *
 * What stops a run is recorded only after the run is taken back, by a short
 * transaction of its own that writes only to the attempt it claimed: a
 * Limited or Shadowbanned Persona holds the action; a banned, deleted or
 * non-Persona author, or something the write can no longer use — the post
 * hidden or deleted, the comment a reply answers gone or hidden — cancels it,
 * and with it every action of its branch still waiting
 * (CancelsDependentStudioActions); an administrator who may no longer approve
 * or anything unexpected fails it; and a Persona at its posting or commenting
 * limit sends it back to Pending, due once the limit allows. A lost connection
 * or a deadlock is no verdict: the action stays Running and recovery sends it
 * again.
 *
 * Nothing runs while the executor is switched off (StudioExecutionSwitch).
 */
final class ExecuteStudioAction
{
    use CancelsDependentStudioActions;
    use DetectsConcurrencyErrors;
    use DetectsLostConnections;
    use LocksUsersInOrder;

    /** The kinds of action that run. The votes wait for the stage that runs them. */
    public const RUNNABLE = [StudioActionType::CreatePost, StudioActionType::CreateComment, StudioActionType::CreateReply];

    public function __construct(
        private readonly CreatePostAction $createPost,
        private readonly ApprovePostAction $approvePost,
        private readonly RecalculatePostScoreAction $recalculateScore,
        private readonly AddCommentAction $addComment,
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
            $written = DB::transaction(fn (): ?Model => $this->run($actionId, $attempt));
        } catch (StudioActionStoppedException $stop) {
            return $this->stop($actionId, $attempt, $stop->failure);
        } catch (RateLimitExceededException $limit) {
            return $this->postpone($actionId, $attempt, $limit->retryAfterSeconds());
        } catch (CannotCommentException $refusal) {
            // Refused before anything was committed: the commenting limit is no
            // verdict on the comment; anything else is said as the stop it is.
            return $refusal->isRateLimited()
                ? $this->postpone($actionId, $attempt, $refusal->retryAfterSeconds() ?? 60)
                : $this->stop($actionId, $attempt, self::failureOf($refusal), $refusal);
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

        if ($written === null) {
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
        $released = null;

        $attempt = DB::transaction(function () use ($actionId, &$released): ?int {
            $action = StudioAction::query()->whereKey($actionId)->lockForUpdate()->first();

            if ($action === null
                || $action->status !== StudioActionStatus::Dispatched
                || ! in_array($action->type, self::RUNNABLE, true)
                || $action->run_after->isFuture()) {
                return null;
            }

            // Sent before what it depends on was done — by hand, or by a
            // dispatcher that did not ask: it is never run before its parent.
            $waitsFor = self::parentStanding($action);

            if ($waitsFor !== null) {
                $released = [$action, $this->release($action, $waitsFor)];

                return null;
            }

            $plan = StudioPlan::query()->whereKey($action->studio_plan_id)->lockForUpdate()->first();

            if ($plan !== null && $plan->status === StudioPlanStatus::Approved) {
                self::saved($plan->forceFill(['status' => StudioPlanStatus::Running]));
            }

            self::saved($action->forceFill(['status' => StudioActionStatus::Running, 'claimed_at' => now()]));

            return $action->attempts;
        });

        if ($released !== null) {
            $this->logReleased($released[0], $released[1]);
        }

        return $attempt;
    }

    /**
     * What the action wrote, and the action done with it — or null when the
     * attempt is no longer this run's.
     */
    private function run(int $actionId, int $attempt): ?Model
    {
        $action = StudioAction::query()->whereKey($actionId)->lockForUpdate()->first();

        if ($action === null || $action->status !== StudioActionStatus::Running || $action->attempts !== $attempt) {
            return null;
        }

        [$written, $plan] = match ($action->type) {
            StudioActionType::CreatePost => $this->publishPost($action),
            StudioActionType::CreateComment, StudioActionType::CreateReply => $this->publishComment($action),
            default => throw new StudioActionStoppedException(StudioActionFailure::UnexpectedFailure),
        };

        self::saved($action->forceFill([
            'status' => StudioActionStatus::Done,
            'result_type' => $written->getMorphClass(),
            'result_id' => $written->getKey(),
            'finished_at' => now(),
            'failure_code' => null,
            'failure_reason' => null,
        ]));

        $this->settlePlan($plan);

        return $written;
    }

    /**
     * The plan's post, written, approved and scored.
     *
     * @return array{0: Post, 1: StudioPlan}
     */
    private function publishPost(StudioAction $action): array
    {
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

        $persona = self::runnablePersona($users->get($action->actor_user_id), comments: false);
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

        return [$post, $plan];
    }

    /**
     * A comment of the plan's discussion on its post, or a reply to one of
     * those comments, as its Persona — dated the action's own `occurs_at`.
     *
     * @return array{0: Comment, 1: StudioPlan}
     */
    private function publishComment(StudioAction $action): array
    {
        $reply = $action->type === StudioActionType::CreateReply;

        // What it is written under, read before any user is locked — the
        // action it depends on, done, and what that wrote — so every user the
        // write touches is locked before the plan; all of it asked again once
        // the rows are locked.
        $parent = self::doneParent($action, $reply ? StudioActionType::CreateComment : StudioActionType::CreatePost);
        $postId = $reply ? self::doneParent($parent, StudioActionType::CreatePost)->result_id : $parent->result_id;
        $answeredId = $reply ? $parent->result_id : null;
        $postAuthorId = Post::withTrashed()->whereKey($postId)->value('user_id');

        if ($postAuthorId === null) {
            throw new StudioActionStoppedException(StudioActionFailure::PostUnavailable);
        }

        $users = $this->lockCommentUsers($action->actor_user_id, (int) $postAuthorId);
        $plan = StudioPlan::query()->whereKey($action->studio_plan_id)->lockForUpdate()->first();

        if ($plan === null || $plan->status !== StudioPlanStatus::Running) {
            throw new StudioActionStoppedException(StudioActionFailure::PlanUnavailable);
        }

        $persona = self::runnablePersona($users->get($action->actor_user_id), comments: true);
        $post = Post::withTrashed()->whereKey($postId)->lockForUpdate()->first();

        // Hidden, deleted or no longer published: no comment is written on it,
        // as none is for a member.
        if ($post === null || ! $post->canReceiveComments()) {
            throw new StudioActionStoppedException(StudioActionFailure::PostUnavailable);
        }

        // Its author never changes, so the rows locked are the ones the write needs.
        if ((int) $post->user_id !== (int) $postAuthorId) {
            throw new StudioActionStoppedException(StudioActionFailure::UnexpectedFailure);
        }

        $answered = null;

        if ($answeredId !== null) {
            $answered = Comment::withTrashed()->whereKey($answeredId)->lockForUpdate()->first();

            // Deleted, hidden, a reply itself or another post's: no reply is
            // written under it, as none is for a member.
            if ($answered === null || $answered->trashed() || $answered->status !== CommentStatus::Visible
                || $answered->parent_id !== null || (int) $answered->post_id !== (int) $post->id) {
                throw new StudioActionStoppedException(StudioActionFailure::ReplyTargetUnavailable);
            }
        }

        $body = $action->payload['body'] ?? null;

        if (! is_string($body)) {
            throw new StudioActionStoppedException(StudioActionFailure::UnexpectedFailure);
        }

        // The comment's own dates are the plan's, however late it runs; the
        // post's comment count and score it changes leave the post's dates
        // as they are.
        $comment = Post::withoutTimestamps(fn (): Comment => Comment::withoutTimestamps(
            fn (): Comment => $this->addComment->handle($persona, $post, $body, $answered, $action->occurs_at),
        ));

        // A comment a model event kept from being saved is no comment to mark done with.
        if (! $comment->exists) {
            throw new RuntimeException('The comment of the studio action\'s run was not saved.');
        }

        return [$comment, $plan];
    }

    /**
     * The `users` rows a comment's run writes under, in ascending id order,
     * before the plan and the post: the commenting Persona's, for update, as
     * AddCommentAction takes it; and the post's author's — who
     * PostCommentedNotification goes to, whose row the notification locks
     * again — so no user is ever first locked after the post.
     *
     * On PostgreSQL the author's row is locked for an update of no key: a
     * member's comment or vote on the same post updates the post twice in its
     * transaction, and PostgreSQL checks the second update's foreign keys by
     * taking FOR KEY SHARE on the author's row while it holds the post — which
     * FOR UPDATE would block, with each side then waiting for the other. FOR NO
     * KEY UPDATE leaves that check alone, and still takes turns with a
     * sanction, an anonymization or another notification of the author, all
     * of them FOR UPDATE; the notification only strengthens the lock this run
     * already holds. Other engines check no key on such an update.
     *
     * @return Collection<int, User> keyed by id; a row gone is absent
     */
    private function lockCommentUsers(int $commenterId, int $authorId): Collection
    {
        $locked = new Collection;
        $ids = array_unique([$commenterId, $authorId]);
        $keyless = (new User)->getConnection()->getDriverName() === 'pgsql';
        sort($ids);

        foreach ($ids as $id) {
            $query = User::query()->whereKey($id);
            $row = ($id === $authorId && $id !== $commenterId && $keyless
                ? $query->lock('for no key update')
                : $query->lockForUpdate())->first();

            if ($row !== null) {
                $locked->put($id, $row);
            }
        }

        return $locked;
    }

    /**
     * The action this one depends on, done, of the kind it must be and of the
     * same plan — or why this one cannot run now.
     */
    private static function doneParent(StudioAction $action, StudioActionType $type): StudioAction
    {
        $parent = $action->parent_action_id !== null ? StudioAction::query()->find($action->parent_action_id) : null;
        $standing = self::parentStanding($action, $parent);

        if ($standing !== null) {
            throw new StudioActionStoppedException($standing);
        }

        $writes = $type === StudioActionType::CreatePost ? new Post : new Comment;

        if ($parent === null || $parent->type !== $type || $parent->studio_plan_id !== $action->studio_plan_id
            || $parent->result_type !== $writes->getMorphClass() || $parent->result_id === null) {
            throw new StudioActionStoppedException(StudioActionFailure::UnexpectedFailure);
        }

        return $parent;
    }

    /**
     * Why an action cannot run yet for what it depends on: its parent not done
     * — it waits — or cancelled for good, or gone; none when it has no parent
     * or its parent is done.
     */
    private static function parentStanding(StudioAction $action, ?StudioAction $parent = null): ?StudioActionFailure
    {
        if ($action->parent_action_id === null) {
            return null;
        }

        $status = $parent->status ?? StudioAction::query()->whereKey($action->parent_action_id)->value('status');
        $status = $status instanceof StudioActionStatus ? $status : StudioActionStatus::tryFrom((string) $status);

        return match ($status) {
            StudioActionStatus::Done => null,
            null, StudioActionStatus::Cancelled => StudioActionFailure::ParentCancelled,
            default => StudioActionFailure::ParentNotDone,
        };
    }

    /**
     * The author, when it may publish now — a post, or a comment — otherwise
     * why it may not.
     */
    private static function runnablePersona(?User $author, bool $comments): User
    {
        if ($author === null) {
            throw new StudioActionStoppedException(StudioActionFailure::AuthorNotPersona);
        }

        $failure = match (true) {
            $author->isTombstoned() => StudioActionFailure::AuthorDeleted,
            ! $author->isPersona() => StudioActionFailure::AuthorNotPersona,
            $author->status === UserStatus::Banned => StudioActionFailure::AuthorBanned,
            // Limited or Shadowbanned: held until an administrator resumes it.
            ! ($comments ? $author->canComment() : $author->canCreateContent()) => StudioActionFailure::AuthorRestricted,
            default => null,
        };

        if ($failure !== null) {
            throw new StudioActionStoppedException($failure);
        }

        return $author;
    }

    /**
     * The plan, once nothing of it is left to run or to decide: Completed when
     * its post was published — its comments done, or cancelled for good —
     * Cancelled when the post never will be. An action still waiting, or held
     * or failed for an administrator, keeps the plan Running; one comment's
     * failure takes back nothing already published.
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
        $released = DB::transaction(function () use ($actionId, $attempt, $failure, $cause): ?array {
            $action = StudioAction::query()->whereKey($actionId)->lockForUpdate()->first();

            if ($action === null || $action->status !== StudioActionStatus::Running || $action->attempts !== $attempt) {
                return null;
            }

            return [$action, $this->release($action, $failure, $cause)];
        });

        if ($released === null) {
            $this->logger->info('studio.action.discarded', ['studio_action_id' => $actionId, 'attempt' => $attempt]);

            return null;
        }

        $this->logReleased($released[0], $released[1], $attempt);

        return $failure->status();
    }

    /**
     * Writes what a stop makes of the locked action, in the caller's
     * transaction: held, cancelled or failed with the reason — or, sent
     * before its parent was done, back to Pending to wait for it, the send
     * counted as a deferral, as the dispatcher counts none of those against
     * `max_attempts`. Cancelled for good, it takes the rest of its branch with
     * it, and its plan is settled.
     *
     * @return list<int> the actions of its branch cancelled with it
     */
    private function release(StudioAction $action, StudioActionFailure $failure, ?Throwable $cause = null): array
    {
        $status = $failure->status();

        self::saved($action->forceFill($status === StudioActionStatus::Pending
            ? [
                'status' => StudioActionStatus::Pending,
                'claimed_at' => null,
                'deferrals' => $action->deferrals + 1,
                'failure_code' => $failure->value,
                'failure_reason' => null,
            ]
            : [
                'status' => $status,
                'failure_code' => $failure->value,
                // Its class alone: a message may carry what the post says.
                'failure_reason' => $cause !== null ? $cause::class : null,
                'finished_at' => $status === StudioActionStatus::Held ? null : now(),
            ]));

        if ($status !== StudioActionStatus::Cancelled) {
            return [];
        }

        // The branch before the plan: action rows are always locked before it.
        $cancelled = $this->cancelBranchBelow($action);
        $plan = StudioPlan::query()->whereKey($action->studio_plan_id)->lockForUpdate()->first();

        if ($plan !== null) {
            $this->settlePlan($plan);
        }

        return $cancelled;
    }

    /**
     * @param  list<int>  $cancelled  the actions of its branch cancelled with it
     */
    private function logReleased(StudioAction $action, array $cancelled, ?int $attempt = null): void
    {
        $failure = StudioActionFailure::from((string) $action->failure_code);
        $status = $failure->status();
        $context = [
            'studio_action_id' => $action->id,
            'attempt' => $attempt ?? $action->attempts,
            'status' => $status->value,
            'failure_code' => $failure->value,
        ];

        $status === StudioActionStatus::Pending
            ? $this->logger->info('studio.action.postponed', [...$context, 'run_after' => $action->run_after->toIso8601String()])
            : $this->logger->warning('studio.action.'.$status->value, $context);

        foreach ($cancelled as $id) {
            $this->logger->warning('studio.action.cancelled', [
                'studio_action_id' => $id,
                'status' => StudioActionStatus::Cancelled->value,
                'failure_code' => StudioActionFailure::ParentCancelled->value,
            ]);
        }
    }

    /**
     * Back to Pending, due once the Persona's posting or commenting limit
     * allows: a limit reached is no verdict on the post or the comment, and is
     * never bypassed. Its date stays the plan's, and `run_after` never moves
     * back. The send
     * still counted as an attempt — the run's fence needs every send counted
     * — and is counted as a deferral too, which the dispatcher leaves out of
     * `max_attempts`: a backlog the limit spreads out is not a run of lost
     * deliveries. Deferrals are bounded on their own: one past
     * `max_deferrals` fails the action, `rate_limited`.
     */
    private function postpone(int $actionId, int $attempt, int $retryAfterSeconds): ?StudioActionStatus
    {
        $maxDeferrals = max(0, (int) config('content_studio.actions.max_deferrals'));

        $postponed = DB::transaction(function () use ($actionId, $attempt, $retryAfterSeconds, $maxDeferrals): ?StudioAction {
            $action = StudioAction::query()->whereKey($actionId)->lockForUpdate()->first();

            if ($action === null || $action->status !== StudioActionStatus::Running || $action->attempts !== $attempt) {
                return null;
            }

            if ($action->deferrals >= $maxDeferrals) {
                self::saved($action->forceFill([
                    'status' => StudioActionStatus::Failed,
                    'failure_code' => StudioActionFailure::RateLimited->value,
                    'failure_reason' => null,
                    'finished_at' => now(),
                ]));

                return $action;
            }

            $runAfter = CarbonImmutable::now()->addSeconds(max(1, $retryAfterSeconds))->startOfSecond();

            self::saved($action->forceFill([
                'status' => StudioActionStatus::Pending,
                'run_after' => $runAfter->greaterThan($action->run_after) ? $runAfter : $action->run_after,
                'claimed_at' => null,
                'deferrals' => $action->deferrals + 1,
                'failure_code' => StudioActionFailure::RateLimited->value,
                'failure_reason' => null,
            ]));

            return $action;
        });

        if ($postponed === null) {
            $this->logger->info('studio.action.discarded', ['studio_action_id' => $actionId, 'attempt' => $attempt]);

            return null;
        }

        $context = [
            'studio_action_id' => $actionId,
            'attempt' => $attempt,
            'deferrals' => $postponed->deferrals,
            'status' => $postponed->status->value,
            'failure_code' => StudioActionFailure::RateLimited->value,
        ];

        if ($postponed->status === StudioActionStatus::Failed) {
            $this->logger->warning('studio.action.failed', $context);

            return StudioActionStatus::Failed;
        }

        $this->logger->info('studio.action.postponed', [...$context, 'run_after' => $postponed->run_after->toIso8601String()]);

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
            $exception instanceof CannotCommentException && $exception->isPostUnavailable() => StudioActionFailure::PostUnavailable,
            $exception instanceof CannotCommentException && $exception->isReplyTargetUnavailable() => StudioActionFailure::ReplyTargetUnavailable,
            $exception instanceof CannotCommentException && $exception->isNotAllowed() => StudioActionFailure::AuthorRestricted,
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
