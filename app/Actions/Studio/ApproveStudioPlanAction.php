<?php

namespace App\Actions\Studio;

use App\Actions\Studio\Concerns\LocksStudioPlanDraft;
use App\Enums\StudioActionStatus;
use App\Enums\StudioActionType;
use App\Enums\StudioPlanApprovalProblem;
use App\Enums\StudioPlanPublicationMode;
use App\Enums\StudioPlanStatus;
use App\Exceptions\Studio\CannotApproveStudioPlanException;
use App\Exceptions\Studio\CannotManageStudioPlanException;
use App\Models\StudioAction;
use App\Models\StudioPlan;
use App\Models\User;
use App\Support\Observability\DomainLogger;
use App\Support\Studio\StudioPlanApprovalPreflight;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * An administrator's approval of a complete Draft: the trusted permission for
 * its Persona to publish it (docs/architecture/studio-plans.md, "Approval").
 * One transaction marks the plan Approved — by whom, when, at a new version —
 * and writes the one action that will publish its post: a CreatePost, Pending,
 * untried. Either both are written or neither is.
 *
 * Nothing is published here, and nothing is sent anywhere: the action is a
 * passive row no queue, worker or schedule reads yet.
 *
 * The locks are taken in the project's order (LocksStudioPlanDraft): the
 * administrator's and the author's `users` rows in ascending id order — the
 * order a sanction of that Persona takes — then the plan. The author is
 * whoever the plan names as the approval starts, read before any lock; a plan
 * found naming another once it is locked was changed meanwhile and is refused
 * as stale, never followed by locking a user after the plan. On the locked
 * rows everything is asked again: the administrator may still manage plans,
 * the plan is still a Draft at the version the administrator saw, and the
 * approval preflight finds nothing to stop it (StudioPlanApprovalPreflight).
 * A plan approved already is no Draft, so a second approval writes no second
 * action.
 *
 * The moment of approval is read once. Published at once, the post happens
 * then (`occurs_at`) and may run from then (`run_after`). On a date, the post
 * happens on that date; a past date may run at once, a future one only from
 * that date.
 */
final class ApproveStudioPlanAction
{
    use LocksStudioPlanDraft;

    public function __construct(
        private readonly StudioPlanApprovalPreflight $preflight,
        private readonly DomainLogger $logger,
    ) {}

    /**
     * @param  int  $expectedLockVersion  the plan's lock_version as the administrator saw it
     * @return StudioPlan the plan, approved; the caller's copy is brought up to date too
     *
     * @throws CannotApproveStudioPlanException with nothing written
     */
    public function handle(User $admin, StudioPlan $plan, int $expectedLockVersion): StudioPlan
    {
        $authorId = null;

        try {
            if (! $admin->can('manage-studio-plans')) {
                throw CannotManageStudioPlanException::becauseUserIsNotAllowed();
            }

            // Read before any lock, so the author's row can be locked before the plan's.
            $authorId = StudioPlan::query()->whereKey($plan->getKey())->value('author_user_id');
            $authorId = $authorId !== null ? (int) $authorId : null;

            DB::transaction(function () use ($admin, $plan, $authorId, $expectedLockVersion): void {
                $rows = $this->lockUsersInOrder((int) $admin->getKey(), ...($authorId !== null ? [$authorId] : []));
                $lockedAdmin = $rows->get($admin->getKey());

                if ($lockedAdmin === null || ! $lockedAdmin->can('manage-studio-plans')) {
                    throw CannotManageStudioPlanException::becauseUserIsNotAllowed();
                }

                $locked = $this->currentDraft((int) $plan->getKey(), $expectedLockVersion);

                // Another administrator chose another author since it was read: the row locked is not the author's.
                if ($locked->author_user_id !== $authorId) {
                    throw CannotManageStudioPlanException::becausePlanChanged();
                }

                $problems = $this->preflight->problems($locked);

                if ($problems !== []) {
                    throw CannotApproveStudioPlanException::becauseOf($problems);
                }

                $approvedAt = CarbonImmutable::now('UTC')->startOfSecond();
                [$occursAt, $runAfter] = self::moments($locked, $approvedAt);

                $locked->forceFill([
                    'status' => StudioPlanStatus::Approved,
                    'approved_by_user_id' => $lockedAdmin->id,
                    'approved_at' => $approvedAt,
                    'lock_version' => $locked->lock_version + 1,
                ])->save();

                $action = new StudioAction;
                $action->forceFill([
                    'studio_plan_id' => $locked->id,
                    'type' => StudioActionType::CreatePost,
                    'actor_user_id' => $authorId,
                    // The approved plan, which nothing writes any more, is what it publishes.
                    'payload' => [],
                    'occurs_at' => $occursAt,
                    'run_after' => $runAfter,
                    'status' => StudioActionStatus::Pending,
                    'attempts' => 0,
                ])->save();

                $plan->setRawAttributes($locked->getAttributes(), true);
                $plan->unsetRelations();

                // Once committed — by the outermost transaction, should this
                // one be inside another — so a rolled-back approval is never
                // logged as one.
                DB::afterCommit(fn () => $this->approved($lockedAdmin, $locked, $action));
            });
        } catch (CannotManageStudioPlanException $refusal) {
            throw $this->rejected($admin, $plan, $authorId, CannotApproveStudioPlanException::because($refusal));
        } catch (CannotApproveStudioPlanException $refusal) {
            throw $this->rejected($admin, $plan, $authorId, $refusal);
        }

        return $plan;
    }

    /**
     * When the post happens, and when it may run.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function moments(StudioPlan $plan, CarbonImmutable $approvedAt): array
    {
        if ($plan->publication_mode !== StudioPlanPublicationMode::AtDate || $plan->publish_at === null) {
            return [$approvedAt, $approvedAt];
        }

        $occursAt = $plan->publish_at->utc()->startOfSecond();

        return [$occursAt, $occursAt->greaterThan($approvedAt) ? $occursAt : $approvedAt];
    }

    private function approved(User $admin, StudioPlan $plan, StudioAction $action): void
    {
        $this->logger->info('studio_plan.approved', [
            'studio_plan_id' => $plan->id,
            'admin_id' => $admin->id,
            'persona_id' => $action->actor_user_id,
        ]);

        $this->logger->info('studio.action.created', [
            'studio_plan_id' => $plan->id,
            'studio_action_id' => $action->id,
            'persona_id' => $action->actor_user_id,
            'type' => $action->type->value,
            'status' => $action->status->value,
            'occurs_at' => $action->occurs_at->toIso8601String(),
            'run_after' => $action->run_after->toIso8601String(),
        ]);
    }

    private function rejected(User $admin, StudioPlan $plan, ?int $authorId, CannotApproveStudioPlanException $refusal): CannotApproveStudioPlanException
    {
        $this->logger->warning('studio_plan.approval_rejected', [
            'studio_plan_id' => $plan->id,
            'admin_id' => $admin->id,
            'persona_id' => $authorId,
            'code' => $refusal->rejection->value,
            'problems' => array_map(fn (StudioPlanApprovalProblem $problem): string => $problem->value, $refusal->problems),
        ]);

        return $refusal;
    }
}
