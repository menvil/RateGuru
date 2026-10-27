<?php

namespace App\Actions\Studio;

use App\Actions\Studio\Concerns\LocksStudioPlanDraft;
use App\Data\Studio\SavedStudioVotes;
use App\Data\Studio\StudioVoteData;
use App\Data\Studio\StudioVotesData;
use App\Enums\StudioActionType;
use App\Enums\VoteType;
use App\Exceptions\Studio\CannotManageStudioPlanException;
use App\Models\StudioPlan;
use App\Models\StudioPlanVote;
use App\Models\User;
use App\Support\Observability\DomainLogger;
use App\Support\Studio\StudioVoteValidationRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * An administrator's save of every vote a Draft prepares
 * (docs/architecture/studio-plans.md, "The votes"): on the post, on the
 * comments of its discussion and for its rating questions, written at once —
 * added, changed and deleted together, or not at all. Nothing is cast: no
 * vote, action or job comes of it, and a plan with votes is not approved
 * until votes can be cast.
 *
 * A vote saved before keeps its id; a new one is given one, which the result
 * names against the key it came with. A vote saved before and left out is
 * deleted. An id that is not this plan's refuses the whole save — a save
 * never reaches another plan's vote.
 *
 * The votes are checked first, needing no lock
 * (StudioVoteValidationRules::validated()); then the administrator's and
 * every voting Persona's `users` rows are locked in ascending id order — all
 * of them named by the save itself, so nothing read meanwhile can change
 * them — then the plan (LocksStudioPlanDraft), never a user after it. On
 * them everything is asked again: the administrator may still manage plans,
 * the plan is still a Draft at the version the editor was drawn from, so a
 * save made before somebody else's is refused rather than written over it,
 * and the votes still hold against the plan, its discussion, the Personas
 * and the rating questions as they are now (validatedAgainst()). The post's
 * author and the comments' Personas it compares with are the locked plan's
 * own: nothing changes them without that lock. The save raises the plan's
 * version.
 */
final class SaveStudioVotesAction
{
    use LocksStudioPlanDraft;

    public function __construct(
        private readonly StudioVoteValidationRules $rules,
        private readonly DomainLogger $logger,
    ) {}

    /**
     * @param  int  $expectedLockVersion  the plan's lock_version as the editor was drawn from it
     * @return SavedStudioVotes the votes as saved, and the plan's new version; the caller's plan is brought up to date too
     *
     * @throws CannotManageStudioPlanException when the administrator may not, or the plan is gone, past its Draft or changed meanwhile
     * @throws ValidationException keyed as the editor's fields
     * @throws RuntimeException when a write of the save is not saved, with nothing written
     */
    public function handle(User $admin, StudioPlan $plan, StudioVotesData $votes, int $expectedLockVersion): SavedStudioVotes
    {
        try {
            if (! $admin->can('manage-studio-plans')) {
                throw CannotManageStudioPlanException::becauseUserIsNotAllowed();
            }

            $votes = $this->rules->validated($votes);

            return DB::transaction(function () use ($admin, $plan, $votes, $expectedLockVersion): SavedStudioVotes {
                $users = $this->lockUsersInOrder((int) $admin->getKey(), ...$votes->personaIds());
                $lockedAdmin = $users->get($admin->getKey());

                if ($lockedAdmin === null || ! $lockedAdmin->can('manage-studio-plans')) {
                    throw CannotManageStudioPlanException::becauseUserIsNotAllowed();
                }

                $locked = $this->currentDraft((int) $plan->getKey(), $expectedLockVersion);
                $votes = $this->rules->validatedAgainst($votes, $locked, $users);

                [$ids, $removed] = $this->write($locked, $votes);

                self::saved($locked->forceFill(['lock_version' => $locked->lock_version + 1]));

                $plan->setRawAttributes($locked->getAttributes(), true);
                $plan->unsetRelations();

                $saved = new SavedStudioVotes(
                    lockVersion: $locked->lock_version,
                    votes: $locked->votes()->get(),
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
            $this->logRejected($admin, $plan, 'invalid_votes', array_keys($invalid->errors()));

            throw $invalid;
        }
    }

    /**
     * Every vote written as the save has it, and every vote it left out
     * deleted.
     *
     * A Persona votes on a target once, which the database holds every row
     * to at every moment. So the votes left out go first, and so does every
     * vote kept but moved to another Persona or target, to be written again
     * with its own id: two votes trading targets would otherwise each meet
     * the other where it was. Then the votes kept in place are changed, and
     * the moved and the new ones written, in the order the save lists them.
     *
     * @return array{0: array<string, int>, 1: int} the id each key was given, and how many votes were deleted
     */
    private function write(StudioPlan $plan, StudioVotesData $votes): array
    {
        $saved = StudioPlanVote::query()->where('studio_plan_id', $plan->id)->orderBy('id')->get()->keyBy('id');
        $kept = collect($votes->votes)->filter(fn (StudioVoteData $vote): bool => $vote->id !== null)->keyBy('id');
        // Filtered, never taken with only() or except(): an Eloquent collection's only() and except() number
        // what they return afresh, and the moved votes are looked up by id.
        $removed = $saved->reject(fn (StudioPlanVote $row): bool => $kept->has($row->id));
        $moved = $saved->filter(fn (StudioPlanVote $row): bool => $kept->has($row->id) && self::moves($row, $kept->get($row->id)));

        foreach ([...$removed->values(), ...$moved->values()] as $row) {
            if ($row->delete() === false) {
                throw new RuntimeException('A vote of the plan was not deleted.');
            }
        }

        $ids = [];

        foreach ([false, true] as $inserting) {
            foreach ($votes->votes as $vote) {
                $row = $vote->id !== null ? $saved->get($vote->id) : null;

                if (($row === null || $moved->has($row->id)) !== $inserting) {
                    continue;
                }

                $id = $this->writeVote($plan, $vote, $row !== null && $moved->has($row->id) ? self::again($row) : $row);

                if ($vote->key !== null) {
                    $ids[$vote->key] = $id;
                }
            }
        }

        return [$ids, $removed->count()];
    }

    /** Whether the save gives a kept vote another Persona or target. */
    private static function moves(StudioPlanVote $row, StudioVoteData $vote): bool
    {
        return $vote->personaUserId !== $row->persona_user_id
            || StudioPlanVote::targetKeyFor($vote->actionType(), $vote->discussionNodeId, $vote->ratingGroupId) !== $row->target_key;
    }

    /** A deleted vote to be written again: its id and its first date kept. */
    private static function again(StudioPlanVote $row): StudioPlanVote
    {
        return (new StudioPlanVote)->forceFill(['id' => $row->id, 'created_at' => $row->created_at]);
    }

    private function writeVote(StudioPlan $plan, StudioVoteData $vote, ?StudioPlanVote $row): int
    {
        $row ??= new StudioPlanVote;
        $type = $vote->actionType();

        self::saved($row->forceFill([
            'studio_plan_id' => $plan->id,
            'persona_user_id' => $vote->personaUserId,
            'type' => $type,
            'vote_type' => $vote->voteType !== null ? VoteType::from($vote->voteType) : null,
            'discussion_node_id' => $type === StudioActionType::VoteComment ? $vote->discussionNodeId : null,
            'rating_group_id' => $type === StudioActionType::VoteRating ? $vote->ratingGroupId : null,
            'rating_option_id' => $type === StudioActionType::VoteRating ? $vote->ratingOptionId : null,
            'offset_seconds' => (int) $vote->offsetSeconds,
            'sort_order' => (int) $vote->sortOrder,
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
            throw new RuntimeException('A write of the votes was not saved: '.$model::class.'.');
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

    private function logSaved(User $admin, StudioPlan $plan, SavedStudioVotes $saved, int $removed): void
    {
        /** @var Collection<string, int> $counts */
        $counts = $saved->votes->countBy(fn (StudioPlanVote $vote): string => $vote->type->value);

        $this->logger->info('studio.votes.saved', [
            'studio_plan_id' => $plan->id,
            'admin_id' => $admin->id,
            'post_votes' => $counts->get(StudioActionType::VotePost->value, 0),
            'comment_votes' => $counts->get(StudioActionType::VoteComment->value, 0),
            'rating_votes' => $counts->get(StudioActionType::VoteRating->value, 0),
            'removed' => $removed,
            'lock_version' => $saved->lockVersion,
        ]);
    }

    /** @param  list<string>  $fields  the editor's fields that failed, never what they hold */
    private function logRejected(User $admin, StudioPlan $plan, string $code, array $fields = []): void
    {
        $this->logger->warning('studio.votes.rejected', [
            'studio_plan_id' => $plan->id,
            'admin_id' => $admin->id,
            'code' => $code,
            'fields' => $fields,
        ]);
    }
}
