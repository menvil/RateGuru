<?php

namespace App\Support\Studio;

use App\Data\Studio\BuiltStudioPostVotes;
use App\Data\Studio\StudioPostVotesSettings;
use App\Data\Studio\StudioVoteData;
use App\Data\Studio\StudioVotesData;
use App\Enums\StudioActionType;
use App\Models\RatingGroup;
use App\Models\StudioPlan;
use App\Models\StudioPlanVote;
use App\Queries\Personas\PersonasQuery;
use App\Support\Rating\RatingConfigurationManager;
use App\Support\Studio\Concerns\DrawsPersonaVotes;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Makes the votes on a plan's post and its rating questions from what the
 * Post votes editor asks for (StudioPostVotesSettings): every vote a Persona
 * is to cast, as StudioVoteData — the votes a plan saves
 * (SaveStudioVotesAction), never a recipe of them. It only reads; nothing is
 * written, and the votes on the discussion's comments are not its to make.
 *
 * Who votes: the Personas chosen by hand, or as many as asked for drawn from
 * the active ones. Never the post's author (the plan's saved one), and a new
 * vote only for an active Persona, able to vote. A Persona chosen earlier and
 * Limited, Shadowbanned or Banned since keeps the vote it has, as a Draft
 * may; a deleted one keeps none.
 *
 * What they cast: each votes the post once, up or down, and answers each
 * rating question once — so the votes on the post, and the answers to one
 * question, each need as many Personas. The answers go to the options asked
 * for: an open option of an active question takes new ones, one no longer
 * open only keeps as many of its saved answers as asked for, never more and
 * never another option's. Too few Personas, an option that is not the
 * question's, a moment past thirty days or the dates a post may carry, or more
 * votes than a plan may prepare: the whole request is refused, keyed by what
 * to change, and nothing is cut to fit.
 *
 * Which Persona casts which: drawn in an order fixed by the settings' seed,
 * so the same settings give the same votes and nothing is drawn again when
 * they are saved. While the saved votes are preferred (`keepSaved`), a
 * Persona keeps its saved vote on a target as far as the counts allow, and its
 * place among the target's votes in time, and a vote that must change goes
 * first to a Persona already voting on that target, so its row is changed
 * rather than replaced.
 *
 * When: within each target — the post, or one question — the votes are cast
 * one after another in a drawn order, the first `firstSeconds` after the
 * post and the last `spreadSeconds` later:
 *
 *     offset(i) = first + floor(i * spread / (n - 1)),  i = 0 … n − 1
 *
 * Votes of one moment keep that order (`sort_order`). Each vote that is a
 * saved one's Persona and target again keeps that vote's id.
 */
final class BuildStudioPostVotesData
{
    use DrawsPersonaVotes;

    public function __construct(
        private readonly RatingConfigurationManager $ratingConfiguration,
        private readonly PersonasQuery $personas,
    ) {}

    /**
     * @throws ValidationException keyed by what to change: `voterCount`, `voters`, `up`, `down`, `post`, `ratings.{group}`, `timing.post`, `timing.{group}`, `votes`
     */
    public function handle(StudioPlan $plan, StudioPostVotesSettings $settings): BuiltStudioPostVotes
    {
        $authorId = $plan->author_user_id;
        $saved = StudioPlanVote::query()
            ->where('studio_plan_id', $plan->getKey())
            ->whereIn('type', [StudioActionType::VotePost, StudioActionType::VoteRating])
            ->orderBy('id')
            ->get();
        $errors = [];

        [$voters, $keepers] = $this->voters($settings, $authorId, $errors);
        $blocks = [$this->postBlock($settings, $saved, $errors), ...$this->ratingBlocks($settings, $saved, $errors)];

        foreach ($blocks as $index => $block) {
            [$blocks[$index]['assigned'], $short] = self::assign($voters, $keepers, $block['previous'], $block['slots'], $block['open'], $settings->keepSaved);

            if ($short > 0) {
                $need = array_sum($block['slots']);
                $errors[$block['field']] ??= $block['label'].' need '.$need.' '.($need === 1 ? 'persona' : 'personas').' who can vote; '
                    .($need - $short).' '.($need - $short === 1 ? 'is' : 'are').' chosen — '.$short.' short. Choose more personas, or ask for fewer.';
            }

            $errors += self::timingErrors($plan, $block, array_sum($block['slots']));
        }

        $comments = StudioPlanVote::query()->where('studio_plan_id', $plan->getKey())->where('type', StudioActionType::VoteComment)->count();
        $total = $comments + array_sum(array_map(fn (array $block): int => array_sum($block['slots']), $blocks));

        if ($total > StudioVoteValidationRules::MOST_VOTES) {
            $errors['votes'] = 'A plan prepares at most '.number_format(StudioVoteValidationRules::MOST_VOTES).' votes; these and the discussion’s come to '.number_format($total).'. Ask for fewer.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return new BuiltStudioPostVotes(
            votes: new StudioVotesData(self::votes($blocks, $saved, $settings->seed, $settings->keepSaved)),
            voters: [...$voters, ...$keepers],
        );
    }

    /**
     * Who may take a new vote — active Personas able to vote, never the
     * post's author — in the order the seed draws them, and who chosen by
     * hand may only keep the votes it has.
     *
     * @param  array<string, string>  $errors
     * @return array{0: list<int>, 1: list<int>}
     */
    private function voters(StudioPostVotesSettings $settings, ?int $authorId, array &$errors): array
    {
        if (! $settings->isManual()) {
            $pool = array_values(array_filter($this->personas->activeIds(), fn (int $id): bool => $id !== $authorId));

            if ($settings->voterCount < 0 || $settings->voterCount > count($pool)) {
                $errors['voterCount'] = 'Choose from 0 to '.count($pool).' personas: that many can vote on this post.';
            }

            return [array_slice(self::drawn($pool, $settings->seed, 'voters'), 0, max(0, min($settings->voterCount, count($pool)))), []];
        }

        return self::chosenVoters($settings->voterIds, $authorId, $settings->seed);
    }

    /**
     * The votes on the post: as many up and down as asked for.
     *
     * @param  Collection<int, StudioPlanVote>  $saved
     * @param  array<string, string>  $errors
     * @return array<string, mixed>
     */
    private function postBlock(StudioPostVotesSettings $settings, Collection $saved, array &$errors): array
    {
        foreach (['up' => $settings->up, 'down' => $settings->down] as $field => $count) {
            if ($count < 0) {
                $errors[$field] = 'Set no fewer than none.';
            }
        }

        return [
            'key' => 'post',
            'field' => 'post',
            'label' => 'Up and down votes',
            'type' => StudioActionType::VotePost,
            'group' => null,
            'slots' => ['up' => max(0, $settings->up), 'down' => max(0, $settings->down)],
            'open' => ['up' => true, 'down' => true],
            'previous' => $saved->where('type', StudioActionType::VotePost)
                ->mapWithKeys(fn (StudioPlanVote $vote): array => [$vote->persona_user_id => (string) $vote->vote_type?->value])
                ->all(),
            'moments' => $saved->where('type', StudioActionType::VotePost)
                ->mapWithKeys(fn (StudioPlanVote $vote): array => [$vote->persona_user_id => $vote->offset_seconds])
                ->all(),
            'first' => $settings->postFirstSeconds,
            'spread' => $settings->postSpreadSeconds,
        ];
    }

    /**
     * The answers to each rating question asked about, in the order the
     * questions are configured: an open option of an active question takes
     * as many as asked for; one no longer open keeps at most the answers it
     * has saved.
     *
     * @param  Collection<int, StudioPlanVote>  $saved
     * @param  array<string, string>  $errors
     * @return list<array<string, mixed>>
     */
    private function ratingBlocks(StudioPostVotesSettings $settings, Collection $saved, array &$errors): array
    {
        $groups = $this->ratingConfiguration->activeGroups();
        $open = $groups->mapWithKeys(fn (RatingGroup $group): array => [(int) $group->id => $group->options->pluck('id')->map(fn (mixed $id): int => (int) $id)->all()])->all();
        $order = array_flip(array_keys($open));
        $ratings = $settings->ratings;
        uksort($ratings, fn (int $a, int $b): int => [$order[$a] ?? PHP_INT_MAX, $a] <=> [$order[$b] ?? PHP_INT_MAX, $b]);
        $blocks = [];

        foreach ($ratings as $rating) {
            $groupId = $rating->groupId;
            $savedHere = $saved->where('type', StudioActionType::VoteRating)->where('rating_group_id', $groupId);
            $label = $groups->firstWhere('id', $groupId)?->translatedLabel() ?? 'This rating question';
            $slots = [];
            $openHere = [];

            foreach ($rating->counts as $optionId => $count) {
                if ($count < 0) {
                    $errors["ratings.{$groupId}"] ??= 'Set no fewer than no answers.';

                    continue;
                }

                $isOpen = in_array($optionId, $open[$groupId] ?? [], true);
                $had = $savedHere->where('rating_option_id', $optionId)->count();

                if (! $isOpen && $count > $had) {
                    $errors["ratings.{$groupId}"] ??= $had > 0
                        ? 'An answer no longer open keeps at most the '.$had.' it had. Move the others to an open answer.'
                        : 'Answer with the options this question has now.';

                    continue;
                }

                if ($count > 0) {
                    $slots["option:{$optionId}"] = $count;
                    $openHere["option:{$optionId}"] = $isOpen;
                }
            }

            $blocks[] = [
                'key' => "rating:{$groupId}",
                'field' => "ratings.{$groupId}",
                'label' => $label.' answers',
                'type' => StudioActionType::VoteRating,
                'group' => $groupId,
                'slots' => $slots,
                'open' => $openHere,
                'previous' => $savedHere
                    ->mapWithKeys(fn (StudioPlanVote $vote): array => [$vote->persona_user_id => 'option:'.$vote->rating_option_id])
                    ->all(),
                'moments' => $savedHere
                    ->mapWithKeys(fn (StudioPlanVote $vote): array => [$vote->persona_user_id => $vote->offset_seconds])
                    ->all(),
                'first' => $rating->firstSeconds,
                'spread' => $rating->spreadSeconds,
            ];
        }

        return $blocks;
    }

    /**
     * Whether a target's votes fit its moments: from the post's own to thirty
     * days after it, the last no later than the dates a post may carry.
     *
     * @param  array<string, mixed>  $block
     * @return array<string, string>
     */
    private static function timingErrors(StudioPlan $plan, array $block, int $count): array
    {
        if ($count === 0) {
            return [];
        }

        $first = (int) $block['first'];
        $last = $first + ($count > 1 ? (int) $block['spread'] : 0);
        $field = $block['key'] === 'post' ? 'timing.post' : 'timing.'.$block['group'];

        return match (true) {
            $first < 0, (int) $block['spread'] < 0 => [$field => 'Nothing can arrive before the post.'],
            $last > StudioVoteValidationRules::LATEST_OFFSET_SECONDS => [$field => $block['label'].' would end more than 30 days after the post. Start sooner, or spread them over less time.'],
            ! StudioDiscussionValidationRules::fitsPostDates($plan->publication_mode, $plan->publish_at, $last) => [$field => $block['label'].' would end after 2038, past the latest date a post may carry. Start sooner, or spread them over less time.'],
            default => [],
        };
    }

    /**
     * Every vote made, in the order they are cast: by moment, then target,
     * then the order drawn within it — each keeping the id of the saved vote
     * of the same Persona on the same target.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @param  Collection<int, StudioPlanVote>  $saved
     * @return list<StudioVoteData>
     */
    private static function votes(array $blocks, Collection $saved, int $seed, bool $keep): array
    {
        $ids = $saved->mapWithKeys(fn (StudioPlanVote $vote): array => [$vote->persona_user_id.'|'.$vote->target_key => (int) $vote->id])->all();
        $entries = [];

        foreach ($blocks as $index => $block) {
            /** @var array<int, string> $assigned */
            $assigned = $block['assigned'];
            $order = self::timeOrder(array_keys($assigned), $block['moments'], $keep, $seed, $block['key']);
            $count = count($order);

            foreach ($order as $place => $persona) {
                $offset = (int) $block['first'] + ($count > 1 ? intdiv($place * (int) $block['spread'], $count - 1) : 0);
                $choice = $assigned[$persona];
                $rating = $block['type'] === StudioActionType::VoteRating;
                $target = StudioPlanVote::targetKeyFor($block['type'], null, $block['group']);

                $entries[] = [$offset, $index, $place, [
                    'id' => $ids[$persona.'|'.$target] ?? null,
                    'type' => $block['type']->value,
                    'personaUserId' => $persona,
                    'voteType' => $rating ? null : $choice,
                    'ratingGroupId' => $rating ? $block['group'] : null,
                    'ratingOptionId' => $rating ? (int) substr($choice, strlen('option:')) : null,
                    'offsetSeconds' => $offset,
                ]];
            }
        }

        usort($entries, fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

        return array_map(fn (array $entry, int $sort): StudioVoteData => new StudioVoteData(...$entry[3], sortOrder: $sort), $entries, array_keys($entries));
    }
}
