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
use App\Models\StudioDiscussionNode;
use App\Models\StudioPlan;
use App\Models\User;
use App\Support\Observability\DomainLogger;
use App\Support\Studio\StudioPlanApprovalPreflight;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * An administrator's approval of a complete Draft: the trusted permission for
 * its Personas to publish it (docs/architecture/studio-plans.md, "Approval").
 * One transaction marks the plan Approved — by whom, when, at a new version —
 * and writes every action that will publish it, Pending and untried: the
 * CreatePost, a CreateComment under it for each comment of the prepared
 * discussion, and a CreateReply under that for each reply — each by the
 * comment's own Persona, its text and its comment's id kept in its payload,
 * read from the discussion as it is now, never from anything a screen sent.
 * Either all of it is written or none of it is.
 *
 * Nothing is published here, and nothing is sent anywhere: the actions are
 * passive rows until the executor is switched on.
 *
 * The locks are taken in the project's order (LocksStudioPlanDraft): the
 * `users` rows of the administrator, the author and every Persona of the
 * discussion, in ascending id order — the order a sanction of one of them
 * takes — then the plan. Who they are is read before any lock; a plan found
 * naming another author, or a discussion found naming other Personas, once it
 * is locked was changed meanwhile and is refused as stale, never followed by
 * locking a user after the plan. On the locked rows everything is asked
 * again: the administrator may still manage plans, the plan is still a Draft
 * at the version the administrator saw, and the approval preflight finds
 * nothing to stop it (StudioPlanApprovalPreflight) — every Persona of the
 * discussion still able to comment included, and no prepared vote, which
 * nothing casts yet. A plan approved already is no Draft, so a second
 * approval writes no second action.
 *
 * The moment of approval is read once. Published at once, the post happens
 * then (`occurs_at`) and may run from then (`run_after`). On a date, the post
 * happens on that date; a past date may run at once, a future one only from
 * that date. Each comment happens its `offset_seconds` after the post and may
 * run from then, or from the approval when that is later — and never before
 * the action it answers is done, which the dispatcher asks besides.
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
     * @throws RuntimeException when a write of the approval is not saved, with nothing written
     */
    public function handle(User $admin, StudioPlan $plan, int $expectedLockVersion): StudioPlan
    {
        $authorId = null;

        try {
            if (! $admin->can('manage-studio-plans')) {
                throw CannotManageStudioPlanException::becauseUserIsNotAllowed();
            }

            // Read before any lock, so every Persona's row can be locked before the plan's.
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
                // for the discussion, since they were read: the rows locked are
                // not theirs.
                if ($locked->author_user_id !== $authorId || self::participantsOf($locked->id) !== $participantIds) {
                    throw CannotManageStudioPlanException::becausePlanChanged();
                }

                $problems = $this->preflight->problems($locked);

                if ($problems !== []) {
                    throw CannotApproveStudioPlanException::becauseOf($problems);
                }

                $approvedAt = CarbonImmutable::now('UTC')->startOfSecond();
                [$occursAt, $runAfter] = self::moments($locked, $approvedAt);

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
                $actions = [self::written(StudioActionType::CreatePost, $locked->id, null, (int) $authorId, [], $occursAt, $runAfter)];
                array_push($actions, ...self::discussionActions($locked, $actions[0], $occursAt, $approvedAt));

                $plan->setRawAttributes($locked->getAttributes(), true);
                $plan->unsetRelations();

                // Once committed — by the outermost transaction, should this
                // one be inside another — so a rolled-back approval is never
                // logged as one.
                DB::afterCommit(fn () => $this->approved($lockedAdmin, $locked, $actions));
            });
        } catch (CannotManageStudioPlanException $refusal) {
            throw $this->rejected($admin, $plan, $authorId, CannotApproveStudioPlanException::because($refusal));
        } catch (CannotApproveStudioPlanException $refusal) {
            throw $this->rejected($admin, $plan, $authorId, $refusal);
        }

        return $plan;
    }

    /**
     * The Personas a plan's discussion names, each once, ascending.
     *
     * @return list<int>
     */
    private static function participantsOf(int $planId): array
    {
        return StudioDiscussionNode::query()
            ->where('studio_plan_id', $planId)
            ->distinct()
            ->orderBy('persona_user_id')
            ->pluck('persona_user_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * An action for every comment of the discussion, under the post's, and
     * for every reply, under its comment's — the parent of each taken from
     * the discussion's own tree, never from its text or its order. Each comment
     * happens its offset after the post, and may run from then, or from the
     * approval when that is later. Written by time — then place, then id — so
     * comments due at once are sent in the order they come.
     *
     * @return list<StudioAction>
     */
    private static function discussionActions(StudioPlan $plan, StudioAction $post, CarbonImmutable $postOccursAt, CarbonImmutable $approvedAt): array
    {
        $nodes = StudioDiscussionNode::query()
            ->where('studio_plan_id', $plan->id)
            ->orderBy('offset_seconds')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $comments = [];
        $written = [];

        foreach ([false, true] as $replies) {
            foreach ($nodes as $node) {
                if ($node->isRoot() === $replies) {
                    continue;
                }

                $parent = $replies ? ($comments[$node->parent_id] ?? null) : $post;

                // The preflight found two levels, each reply under a comment of this plan.
                if ($parent === null) {
                    throw new RuntimeException('A reply of the approved discussion has no comment of it to answer.');
                }

                $occursAt = $postOccursAt->addSeconds($node->offset_seconds);
                $action = self::written(
                    $replies ? StudioActionType::CreateReply : StudioActionType::CreateComment,
                    $plan->id,
                    $parent->id,
                    $node->persona_user_id,
                    ['discussion_node_id' => $node->id, 'body' => trim($node->body)],
                    $occursAt,
                    $occursAt->greaterThan($approvedAt) ? $occursAt : $approvedAt,
                );

                $comments[$node->id] = $action;
                $written[] = $action;
            }
        }

        return $written;
    }

    /**
     * One action, Pending and untried — or the whole approval taken back: a
     * save a model event cancels returns false rather than throwing.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function written(StudioActionType $type, int $planId, ?int $parentId, int $actorId, array $payload, CarbonImmutable $occursAt, CarbonImmutable $runAfter): StudioAction
    {
        $action = new StudioAction;

        $saved = $action->forceFill([
            'studio_plan_id' => $planId,
            'parent_action_id' => $parentId,
            'type' => $type,
            'actor_user_id' => $actorId,
            'payload' => $payload,
            'occurs_at' => $occursAt,
            'run_after' => $runAfter,
            'status' => StudioActionStatus::Pending,
            'attempts' => 0,
        ])->save();

        if (! $saved) {
            throw new RuntimeException('An action of the approved plan was not saved.');
        }

        return $action;
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
