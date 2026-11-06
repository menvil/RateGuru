<?php

namespace App\Actions\Studio;

use App\Actions\Studio\Concerns\LocksStudioPlanDraft;
use App\Data\Studio\PlannedStudioAction;
use App\Enums\StudioActionStatus;
use App\Enums\StudioPlanApprovalProblem;
use App\Enums\StudioPlanStatus;
use App\Exceptions\Studio\CannotApproveStudioPlanException;
use App\Exceptions\Studio\CannotManageStudioPlanException;
use App\Models\StudioAction;
use App\Models\StudioDiscussionNode;
use App\Models\StudioPlan;
use App\Models\StudioPlanVote;
use App\Models\User;
use App\Support\Observability\DomainLogger;
use App\Support\Studio\StudioDispatchWake;
use App\Support\Studio\StudioPlanApprovalPreflight;
use App\Support\Studio\StudioPlanSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * An administrator's approval of a complete Draft: the trusted permission for
 * its Personas to publish it (docs/architecture/studio-plans.md, "Approval").
 * One transaction marks the plan Approved — by whom, when, at a new version —
 * and writes every action that will publish it, Pending and untried: exactly
 * the set StudioPlanSchedule works out from the plan as it is now, never from
 * anything a screen sent — the CreatePost, a CreateComment or CreateReply for
 * each comment of the prepared discussion and an action for each prepared
 * vote, each under the action it depends on. Either all of it is written or
 * none of it is.
 *
 * Nothing is published here. Once the approval has committed, the dispatcher
 * is woken (StudioDispatchWake), so a post due at once is sent to the
 * executor straight away rather than at the next minute — when the executor
 * is switched on; until then the actions are passive rows.
 *
 * The locks are taken in the project's order (LocksStudioPlanDraft): the
 * `users` rows of the administrator, the author, every Persona of the
 * discussion and every Persona of the prepared votes, each once, in ascending
 * id order — the order a sanction of one of them, and a save of the votes,
 * takes — then the plan. Who they are is read before any lock; a plan found
 * naming another author, or a discussion or votes found naming other
 * Personas, once it is locked was changed meanwhile and is refused as stale,
 * never followed by locking a user after the plan. On the locked rows
 * everything is asked again: the administrator may still manage plans, the
 * plan is still a Draft at the version the administrator saw, and the
 * approval preflight finds nothing to stop it (StudioPlanApprovalPreflight) —
 * every Persona of the discussion still able to comment included, and every
 * prepared vote one the executor can cast. A plan approved already is no
 * Draft, so a second approval writes no second action.
 *
 * The moment of approval is read once, and decides when each action may run
 * (StudioPlanSchedule): a past date runs as soon as it may, under that past
 * date; a future one waits for it.
 */
final class ApproveStudioPlanAction
{
    use LocksStudioPlanDraft;

    public function __construct(
        private readonly StudioPlanApprovalPreflight $preflight,
        private readonly StudioPlanSchedule $schedule,
        private readonly StudioDispatchWake $wake,
        private readonly DomainLogger $logger,
    ) {}

    /**
     * @param  int  $expectedLockVersion  the plan's lock_version as the administrator saw it
     * @return StudioPlan the plan, approved; the caller's copy is brought up to date too
     *
     * @throws CannotApproveStudioPlanException with nothing written
     * @throws RuntimeException when a write of the approval is not saved, with nothing written
     */
    public function handle(User $admin, StudioPlan $plan, int $expectedLockVersion): StudioPlan
    {
        $authorId = null;

        try {
            if (! $admin->can('manage-studio-plans')) {
                throw CannotManageStudioPlanException::becauseUserIsNotAllowed();
            }

            // Read before any lock, so every Persona's row — the discussion's
            // and the votes' — can be locked before the plan's.
            $authorId = StudioPlan::query()->whereKey($plan->getKey())->value('author_user_id');
            $authorId = $authorId !== null ? (int) $authorId : null;
            $participantIds = self::participantsOf((int) $plan->getKey());

            DB::transaction(function () use ($admin, $plan, $authorId, $participantIds, $expectedLockVersion): void {
                $rows = $this->lockUsersInOrder((int) $admin->getKey(), ...($authorId !== null ? [$authorId] : []), ...$participantIds);
                $lockedAdmin = $rows->get($admin->getKey());

                if ($lockedAdmin === null || ! $lockedAdmin->can('manage-studio-plans')) {
                    throw CannotManageStudioPlanException::becauseUserIsNotAllowed();
                }

                $locked = $this->currentDraft((int) $plan->getKey(), $expectedLockVersion);

                // Another administrator chose another author, or other Personas
                // for the discussion or the votes, since they were read: the
                // rows locked are not theirs.
                if ($locked->author_user_id !== $authorId || self::participantsOf($locked->id) !== $participantIds) {
                    throw CannotManageStudioPlanException::becausePlanChanged();
                }

                $problems = $this->preflight->problems($locked);

                if ($problems !== []) {
                    throw CannotApproveStudioPlanException::becauseOf($problems);
                }

                $approvedAt = CarbonImmutable::now('UTC')->startOfSecond();
                $planned = $this->schedule->of($locked, $approvedAt);

                // A save a model event cancels returns false rather than
                // throwing: either is taken as the whole approval failing, so
                // the transaction takes both writes back together.
                $approved = $locked->forceFill([
                    'status' => StudioPlanStatus::Approved,
                    'approved_by_user_id' => $lockedAdmin->id,
                    'approved_at' => $approvedAt,
                    'lock_version' => $locked->lock_version + 1,
                ])->save();

                if (! $approved) {
                    throw new RuntimeException('The publication plan was not saved as approved.');
                }

                // The approved plan, which nothing writes any more, is what the post publishes.
                $actions = self::written($locked->id, $planned);

                $plan->setRawAttributes($locked->getAttributes(), true);
                $plan->unsetRelations();

                // Once committed — by the outermost transaction, should this
                // one be inside another — so a rolled-back approval is never
                // logged as one.
                DB::afterCommit(fn () => $this->approved($lockedAdmin, $locked, $actions));
                $this->wake->afterCommit();
            });
        } catch (CannotManageStudioPlanException $refusal) {
            throw $this->rejected($admin, $plan, $authorId, CannotApproveStudioPlanException::because($refusal));
        } catch (CannotApproveStudioPlanException $refusal) {
            throw $this->rejected($admin, $plan, $authorId, $refusal);
        }

        return $plan;
    }

    /**
     * The Personas a plan's discussion and its prepared votes name, each once,
     * ascending.
     *
     * @return list<int>
     */
    private static function participantsOf(int $planId): array
    {
        $ids = [
            ...StudioDiscussionNode::query()->where('studio_plan_id', $planId)->distinct()->pluck('persona_user_id')->all(),
            ...StudioPlanVote::query()->where('studio_plan_id', $planId)->distinct()->pluck('persona_user_id')->all(),
        ];
        $ids = array_values(array_unique(array_map(fn (mixed $id): int => (int) $id, $ids)));
        sort($ids);

        return $ids;
    }

    /**
     * Every planned action, in order, each under the action written for the
     * one it depends on — or the whole approval taken back: a save a model
     * event cancels returns false rather than throwing.
     *
     * @param  non-empty-list<PlannedStudioAction>  $planned  the post's first
     * @return non-empty-list<StudioAction> the post's first
     */
    private static function written(int $planId, array $planned): array
    {
        $written = [];

        foreach ($planned as $item) {
            $parent = $item->parentKey !== null ? ($written[$item->parentKey] ?? null) : null;

            // The preflight found two levels, each reply under a comment of
            // this plan, and every vote on the post or a comment of it, whose
            // action is written before it.
            if ($item->key !== 'post' && $parent === null) {
                throw new RuntimeException('An action of the approved plan depends on no action of it written before it.');
            }

            $action = new StudioAction;

            $saved = $action->forceFill([
                'studio_plan_id' => $planId,
                'parent_action_id' => $parent?->id,
                'type' => $item->type,
                'actor_user_id' => (int) $item->actorId,
                'payload' => $item->payload,
                'occurs_at' => $item->occursAt,
                'run_after' => $item->runAfter,
                'status' => StudioActionStatus::Pending,
                'attempts' => 0,
            ])->save();

            if (! $saved) {
                throw new RuntimeException('An action of the approved plan was not saved.');
            }

            $written[$item->key] = $action;
        }

        return array_values($written);
    }

    /** @param  non-empty-list<StudioAction>  $actions  the post's first */
    private function approved(User $admin, StudioPlan $plan, array $actions): void
    {
        $this->logger->info('studio_plan.approved', [
            'studio_plan_id' => $plan->id,
            'admin_id' => $admin->id,
            'persona_id' => $actions[0]->actor_user_id,
            'actions' => count($actions),
        ]);

        foreach ($actions as $action) {
            $this->logger->info('studio.action.created', [
                'studio_plan_id' => $plan->id,
                'studio_action_id' => $action->id,
                'parent_action_id' => $action->parent_action_id,
                'persona_id' => $action->actor_user_id,
                'type' => $action->type->value,
                'status' => $action->status->value,
                'occurs_at' => $action->occurs_at->toIso8601String(),
                'run_after' => $action->run_after->toIso8601String(),
            ]);
        }
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
