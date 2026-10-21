<?php

namespace App\Support\Studio;

use App\Data\Studio\BuiltStudioDiscussionVotes;
use App\Data\Studio\StudioDiscussionVotesSettings;
use App\Data\Studio\StudioDiscussionVoteTargetSettings;
use App\Data\Studio\StudioVoteData;
use App\Data\Studio\StudioVotesData;
use App\Enums\StudioActionType;
use App\Models\StudioDiscussionNode;
use App\Models\StudioPlan;
use App\Models\StudioPlanVote;
use App\Queries\Personas\PersonasQuery;
use App\Support\Studio\Concerns\DrawsPersonaVotes;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Makes the votes on a plan's prepared comments and replies from what the
 * Comment votes editor asks for (StudioDiscussionVotesSettings): every vote a
 * Persona is to cast on one of them, as StudioVoteData — the votes a plan
 * saves (SaveStudioVotesAction), never a recipe of them. It only reads:
 * nothing is written, and the votes on the post and its rating questions are
 * not its to make.
 *
 * Who votes: the Personas chosen by hand, or as many as asked for drawn from
 * the active ones — on each comment, every one of them but the comment's own
 * Persona, who votes on the others all the same. A new vote only for an active
 * Persona able to vote; one chosen earlier and restricted since keeps the vote
 * it has, and a deleted one keeps none.
 *
 * What they cast: each votes on a comment once, up or down, so a comment's
 * votes need as many of its voters; one Persona votes on as many comments as
 * it is given. Too few voters for a comment, a comment the discussion no
 * longer has, a vote past thirty days or the dates a post may carry, or more
 * votes than a plan may prepare with the post's own: the whole request is
 * refused, keyed by the comment to change, and nothing is cut to fit.
 *
 * Which Persona casts which: drawn in an order fixed by the settings' seed.
 * While the saved votes are preferred, a Persona keeps its saved vote on a
 * comment as far as the counts allow, and its place among the comment's votes
 * in time; a vote that must change goes first to a Persona already voting on
 * that comment, so its row is changed rather than replaced — and keeps its
 * id. Built again from the settings the saved votes were drawn as, the votes
 * are the saved ones.
 *
 * When: a comment's votes come one after another from its own moment, the
 * first `firstSeconds` after it and the last `spreadSeconds` later:
 *
 *     offset(i) = comment + first + floor(i * spread / (n - 1)),  i = 0 … n − 1
 *
 * — seconds after the post, as every prepared vote is kept. Votes of one
 * moment keep the order drawn (`sort_order`).
 */
final class BuildStudioDiscussionVotesData
{
    use DrawsPersonaVotes;

    public function __construct(
        private readonly PersonasQuery $personas,
    ) {}

    /**
     * @throws ValidationException keyed by what to change: `voterCount`, `targets.{node}`, `timing.{node}`, `votes`
     */
    public function handle(StudioPlan $plan, StudioDiscussionVotesSettings $settings): BuiltStudioDiscussionVotes
    {
        $nodes = StudioDiscussionNode::query()
            ->where('studio_plan_id', $plan->getKey())
            ->get(['id', 'persona_user_id', 'offset_seconds'])
            ->keyBy('id');
        $saved = StudioPlanVote::query()
            ->where('studio_plan_id', $plan->getKey())
            ->where('type', StudioActionType::VoteComment)
            ->orderBy('id')
            ->get();
        $errors = [];

        [$voters, $keepers] = $this->voters($settings, $errors);
        $targets = $settings->targets;
        uasort($targets, fn (StudioDiscussionVoteTargetSettings $a, StudioDiscussionVoteTargetSettings $b): int => [$nodes->get($a->nodeId)->offset_seconds ?? PHP_INT_MAX, $a->nodeId] <=> [$nodes->get($b->nodeId)->offset_seconds ?? PHP_INT_MAX, $b->nodeId]);
        $blocks = [];

        foreach ($targets as $target) {
            $node = $nodes->get($target->nodeId);
            $field = "targets.{$target->nodeId}";

            if ($node === null) {
                $errors[$field] = 'This comment is no longer in the discussion. Reload the plan.';

                continue;
            }

            if ($target->up < 0 || $target->down < 0) {
                $errors[$field] = 'Set no fewer than no votes.';

                continue;
            }

            if ($target->total() === 0) {
                continue;
            }

            // Its own Persona never votes on it, and votes on every other comment all the same.
            $others = fn (array $ids): array => array_values(array_filter($ids, fn (int $id): bool => $id !== $node->persona_user_id));
            [$assigned, $short] = self::assign(
                $others($voters),
                $others($keepers),
                $saved->where('discussion_node_id', $node->id)
                    ->mapWithKeys(fn (StudioPlanVote $vote): array => [$vote->persona_user_id => (string) $vote->vote_type?->value])
                    ->all(),
                ['up' => $target->up, 'down' => $target->down],
                ['up' => true, 'down' => true],
                $settings->keepSaved,
            );

            if ($short > 0) {
                $need = $target->total();
                $errors[$field] = 'Its '.$need.' '.($need === 1 ? 'vote needs' : 'votes need').' '.$need.' '.($need === 1 ? 'persona' : 'personas').' who can vote on it; '
                    .($need - $short).' can — '.$short.' short. Choose more personas, or ask for fewer.';
            }

            $errors += self::timingErrors($plan, $node, $target);
            $blocks[] = ['node' => $node, 'target' => $target, 'assigned' => $assigned, 'order' => self::timeOrder(
                array_keys($assigned),
                $saved->where('discussion_node_id', $node->id)->mapWithKeys(fn (StudioPlanVote $vote): array => [$vote->persona_user_id => $vote->offset_seconds])->all(),
                $settings->keepSaved,
                $settings->seed,
                'comment:'.$node->id,
            )];
        }

        $others = StudioPlanVote::query()->where('studio_plan_id', $plan->getKey())->where('type', '!=', StudioActionType::VoteComment)->count();
        $total = $others + array_sum(array_map(fn (StudioDiscussionVoteTargetSettings $target): int => max(0, $target->total()), $settings->targets));

        if ($total > StudioVoteValidationRules::MOST_VOTES) {
            $errors['votes'] = 'A plan prepares at most '.number_format(StudioVoteValidationRules::MOST_VOTES).' votes; these and the post’s come to '.number_format($total).'. Ask for fewer.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return new BuiltStudioDiscussionVotes(
            votes: new StudioVotesData(self::votes($blocks, $saved)),
            voters: [...$voters, ...$keepers],
        );
    }

    /**
     * A whole number of votes shared among comments in proportion to their
     * priorities' weights (StudioDiscussionVoteTargetSettings::WEIGHTS), the
     * remainders to the largest fractions and, between equal ones, to the
     * comment first in the order given — the tree's, then the id. Nothing is
     * drawn.
     *
     * @param  array<int, string>  $priorities  each comment's priority, by node id, in the tree's order
     * @return array<int, int> each comment's share, by node id
     */
    public static function distribute(array $priorities, int $total): array
    {
        $weights = array_map(fn (string $priority): int => StudioDiscussionVoteTargetSettings::WEIGHTS[$priority] ?? StudioDiscussionVoteTargetSettings::WEIGHTS['normal'], $priorities);
        $sum = array_sum($weights);
        $shares = [];
        $rests = [];
        $place = 0;

        foreach ($weights as $node => $weight) {
            $exact = $sum > 0 ? max(0, $total) * $weight / $sum : 0;
            $shares[$node] = (int) floor($exact);
            $rests[] = [$exact - floor($exact), -$place++, $node];
        }

        rsort($rests);

        for ($left = max(0, $total) - array_sum($shares), $at = 0; $left > 0 && $rests !== []; $left--, $at++) {
            $shares[$rests[$at % count($rests)][2]]++;
        }

        return $shares;
    }

    /**
     * Who may take a new vote, in the order the seed draws them — active
     * Personas able to vote — and who chosen by hand may only keep the votes
     * it has.
     *
     * @param  array<string, string>  $errors
     * @return array{0: list<int>, 1: list<int>}
     */
    private function voters(StudioDiscussionVotesSettings $settings, array &$errors): array
    {
        if ($settings->isManual()) {
            return self::chosenVoters($settings->voterIds, null, $settings->seed);
        }

        $pool = $this->personas->activeIds();

        if ($settings->voterCount < 0 || $settings->voterCount > count($pool)) {
            $errors['voterCount'] = 'Choose from 0 to '.count($pool).' personas: that many can vote.';
        }

        return [array_slice(self::drawn($pool, $settings->seed, 'voters'), 0, max(0, min($settings->voterCount, count($pool)))), []];
    }

    /**
     * Whether a comment's votes fit their moments: from the comment's own to
     * thirty days after the post, the last no later than the dates a post may
     * carry.
     *
     * @return array<string, string>
     */
    private static function timingErrors(StudioPlan $plan, StudioDiscussionNode $node, StudioDiscussionVoteTargetSettings $target): array
    {
        $first = $node->offset_seconds + $target->firstSeconds;
        $last = $first + ($target->total() > 1 ? $target->spreadSeconds : 0);
        $field = "timing.{$node->id}";

        return match (true) {
            $target->firstSeconds < 0, $target->spreadSeconds < 0 => [$field => 'No vote can come before the comment it is on.'],
            $last > StudioVoteValidationRules::LATEST_OFFSET_SECONDS => [$field => 'Its votes would end more than 30 days after the post. Start sooner, or spread them over less time.'],
            ! StudioDiscussionValidationRules::fitsPostDates($plan->publication_mode, $plan->publish_at, $last) => [$field => 'Its votes would end after 2038, past the latest date a post may carry. Start sooner, or spread them over less time.'],
            default => [],
        };
    }

    /**
     * Every vote made, in the order they are cast: by moment, then comment,
     * then the order drawn within it — each keeping the id of the saved vote
     * of the same Persona on the same comment.
     *
     * @param  list<array{node: StudioDiscussionNode, target: StudioDiscussionVoteTargetSettings, assigned: array<int, string>, order: list<int>}>  $blocks
     * @param  Collection<int, StudioPlanVote>  $saved
     * @return list<StudioVoteData>
     */
    private static function votes(array $blocks, Collection $saved): array
    {
        $ids = $saved->mapWithKeys(fn (StudioPlanVote $vote): array => [$vote->persona_user_id.'|'.$vote->discussion_node_id => (int) $vote->id])->all();
        $entries = [];

        foreach ($blocks as $index => $block) {
            $node = $block['node'];
            $order = $block['order'];
            $count = count($order);

            foreach ($order as $place => $persona) {
                $offset = $node->offset_seconds + $block['target']->firstSeconds
                    + ($count > 1 ? intdiv($place * $block['target']->spreadSeconds, $count - 1) : 0);

                $entries[] = [$offset, $index, $place, [
                    'id' => $ids[$persona.'|'.$node->id] ?? null,
                    'type' => StudioActionType::VoteComment->value,
                    'personaUserId' => $persona,
                    'voteType' => $block['assigned'][$persona],
                    'discussionNodeId' => (int) $node->id,
                    'offsetSeconds' => $offset,
                ]];
            }
        }

        usort($entries, fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

        return array_map(fn (array $entry, int $sort): StudioVoteData => new StudioVoteData(...$entry[3], sortOrder: $sort), $entries, array_keys($entries));
    }
}
