<?php

namespace App\Actions\Studio;

use App\Actions\Studio\Concerns\LocksStudioPlanDraft;
use App\Data\Studio\SavedStudioDiscussion;
use App\Data\Studio\StudioDiscussionData;
use App\Data\Studio\StudioDiscussionNodeData;
use App\Exceptions\Studio\CannotManageStudioPlanException;
use App\Models\StudioDiscussionNode;
use App\Models\StudioPlan;
use App\Models\User;
use App\Support\Observability\DomainLogger;
use App\Support\Studio\StudioDiscussionValidationRules;
use App\Support\Studio\StudioVoteValidationRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * An administrator's save of a Draft's whole discussion
 * (docs/architecture/studio-plans.md, "The discussion"): every comment and
 * reply it is to have, written at once — added, changed and deleted together,
 * or not at all. Nothing is published: no comment, post, action or job comes
 * of it — approving the plan is what has its comments written.
 *
 * A comment saved before keeps its id; a new one is given one, which the
 * result names against the key it came with, so a new reply may answer a new
 * comment of the same save. A comment saved before and left out is deleted,
 * and its replies with it; a reply left out takes nothing else with it. An id
 * that is not this plan's refuses the whole save — a save never reaches
 * another plan's comment.
 *
 * The discussion is checked first, needing no lock
 * (StudioDiscussionValidationRules::validated()); then the administrator's
 * and every named Persona's `users` rows are locked in ascending id order,
 * then the plan (LocksStudioPlanDraft) — never a user after the plan — and
 * everything is asked again on them: the administrator may still manage
 * plans, the plan is still a Draft at the version the editor was drawn from,
 * so a save made before somebody else's is refused rather than written over
 * it, and the discussion still holds against the plan and the Personas as
 * they are now (validateAgainst()) and against the votes prepared on its
 * comments (StudioVoteValidationRules::validateDiscussion()): a comment kept
 * keeps its votes, so it is neither moved past one of them nor given to a
 * Persona voting on it, and a comment deleted takes its votes with it. The
 * save raises the plan's version.
 */
final class SaveStudioDiscussionTreeAction
{
    use LocksStudioPlanDraft;

    public function __construct(
        private readonly StudioDiscussionValidationRules $rules,
        private readonly StudioVoteValidationRules $voteRules,
        private readonly DomainLogger $logger,
    ) {}

    /**
     * @param  int  $expectedLockVersion  the plan's lock_version as the editor was drawn from it
     * @return SavedStudioDiscussion the discussion as saved, and the plan's new version; the caller's plan is brought up to date too
     *
     * @throws CannotManageStudioPlanException when the administrator may not, or the plan is gone, past its Draft or changed meanwhile
     * @throws ValidationException keyed as the editor's fields
     * @throws RuntimeException when a write of the save is not saved, with nothing written
     */
    public function handle(User $admin, StudioPlan $plan, StudioDiscussionData $discussion, int $expectedLockVersion): SavedStudioDiscussion
    {
        try {
            if (! $admin->can('manage-studio-plans')) {
                throw CannotManageStudioPlanException::becauseUserIsNotAllowed();
            }

            $discussion = $this->rules->validated($discussion);

            return DB::transaction(function () use ($admin, $plan, $discussion, $expectedLockVersion): SavedStudioDiscussion {
                $users = $this->lockUsersInOrder((int) $admin->getKey(), ...$discussion->personaIds());
                $lockedAdmin = $users->get($admin->getKey());

                if ($lockedAdmin === null || ! $lockedAdmin->can('manage-studio-plans')) {
                    throw CannotManageStudioPlanException::becauseUserIsNotAllowed();
                }

                $locked = $this->currentDraft((int) $plan->getKey(), $expectedLockVersion);
                $this->rules->validateAgainst($discussion, $locked, $users);
                $this->voteRules->validateDiscussion($discussion, $locked);

                [$ids, $removed] = $this->write($locked, $discussion);

                self::saved($locked->forceFill(['lock_version' => $locked->lock_version + 1]));

                $plan->setRawAttributes($locked->getAttributes(), true);
                $plan->unsetRelations();

                $saved = new SavedStudioDiscussion(
                    lockVersion: $locked->lock_version,
                    nodes: $locked->discussionNodes()->get(),
                    ids: $ids,
                );

                // Once committed — by the outermost transaction, should this
                // one be inside another — so a rolled-back save is never
                // logged as one.
                DB::afterCommit(fn () => $this->logSaved($lockedAdmin, $locked, $saved, $removed));

                return $saved;
            });
        } catch (CannotManageStudioPlanException $refusal) {
            $this->logRejected($admin, $plan, self::refusalCode($refusal));

            throw $refusal;
        } catch (ValidationException $invalid) {
            $this->logRejected($admin, $plan, 'invalid_discussion', array_keys($invalid->errors()));

            throw $invalid;
        }
    }

    /**
     * Every comment written as the discussion has it, and every comment it
     * left out deleted: the comments on the post first, so a new reply has the
     * id of a new comment to answer, then the replies, then the deletions —
     * once nothing kept answers a comment any more.
     *
     * @return array{0: array<string, int>, 1: Collection<int, StudioDiscussionNode>} the id each key was given, and the comments deleted
     */
    private function write(StudioPlan $plan, StudioDiscussionData $discussion): array
    {
        $saved = StudioDiscussionNode::query()->where('studio_plan_id', $plan->id)->get()->keyBy('id');
        $parents = StudioDiscussionValidationRules::parentIndexes($discussion);
        $written = [];
        $ids = [];

        foreach ([false, true] as $replies) {
            foreach ($discussion->nodes as $index => $node) {
                if ($node->isReply() !== $replies) {
                    continue;
                }

                $parentId = $parents[$index] !== null ? $written[$parents[$index]] : null;
                $written[$index] = $this->writeNode($plan, $node, $parentId, $saved->get($node->id ?? 0));

                if ($node->key !== null) {
                    $ids[$node->key] = $written[$index];
                }
            }
        }

        $removed = $saved->except(array_values($written));

        // Replies before the comments they answer, so none is deleted twice.
        foreach ($removed->sortBy(fn (StudioDiscussionNode $node): int => $node->isRoot() ? 1 : 0) as $node) {
            if ($node->delete() === false) {
                throw new RuntimeException('A comment of the discussion was not deleted.');
            }
        }

        return [$ids, $removed];
    }

    private function writeNode(StudioPlan $plan, StudioDiscussionNodeData $node, ?int $parentId, ?StudioDiscussionNode $saved): int
    {
        $row = $saved ?? new StudioDiscussionNode;

        self::saved($row->forceFill([
            'studio_plan_id' => $plan->id,
            'parent_id' => $parentId,
            'persona_user_id' => $node->personaUserId,
            'body' => $node->body,
            'sort_order' => $node->sortOrder,
            'offset_seconds' => $node->offsetSeconds,
        ]));

        return (int) $row->getKey();
    }

    /**
     * A save a model event cancels returns false rather than throwing; here
     * it fails the whole save, as an exception would.
     */
    private static function saved(Model $model): void
    {
        if (! $model->save()) {
            throw new RuntimeException('A write of the discussion was not saved: '.$model::class.'.');
        }
    }

    private static function refusalCode(CannotManageStudioPlanException $refusal): string
    {
        return match (true) {
            $refusal->isNotAllowed() => 'access_denied',
            $refusal->isGone() => 'plan_gone',
            $refusal->isNotDraft() => 'not_draft',
            $refusal->isStale() => 'stale_version',
            default => 'unavailable',
        };
    }

    /** @param  Collection<int, StudioDiscussionNode>  $removed */
    private function logSaved(User $admin, StudioPlan $plan, SavedStudioDiscussion $saved, Collection $removed): void
    {
        $this->logger->info('studio.discussion.saved', [
            'studio_plan_id' => $plan->id,
            'admin_id' => $admin->id,
            'roots' => $saved->roots()->count(),
            'replies' => $saved->nodes->count() - $saved->roots()->count(),
            'lock_version' => $saved->lockVersion,
        ]);

        foreach ($removed->filter(fn (StudioDiscussionNode $node): bool => $node->isRoot()) as $root) {
            $this->logger->info('studio.discussion.deleted_branch', [
                'studio_plan_id' => $plan->id,
                'admin_id' => $admin->id,
                'root_id' => $root->id,
                'replies' => $removed->where('parent_id', $root->id)->count(),
                'lock_version' => $saved->lockVersion,
            ]);
        }
    }

    /** @param  list<string>  $fields  the editor's fields that failed, never what they hold */
    private function logRejected(User $admin, StudioPlan $plan, string $code, array $fields = []): void
    {
        $this->logger->warning('studio.discussion.rejected', [
            'studio_plan_id' => $plan->id,
            'admin_id' => $admin->id,
            'code' => $code,
            'fields' => $fields,
        ]);
    }
}
