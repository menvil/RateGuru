<?php

namespace App\Data\Studio;

use App\Models\StudioPlan;
use App\Models\StudioPlanVote;

/**
 * Every vote a Draft prepares, as it is to be saved: the whole set, and
 * nothing else. A vote saved before and left out is deleted; none at all is
 * every vote taken away.
 */
final readonly class StudioVotesData
{
    /** @param  list<StudioVoteData>  $votes  in the order they are listed */
    public function __construct(
        public array $votes = [],
    ) {}

    /** A plan's votes as they are saved, in their order. */
    public static function of(StudioPlan $plan): self
    {
        return new self($plan->votes()->get()
            ->map(fn (StudioPlanVote $vote): StudioVoteData => StudioVoteData::of($vote))
            ->values()
            ->all());
    }

    public function normalized(): self
    {
        return new self(array_map(fn (StudioVoteData $vote): StudioVoteData => $vote->normalized(), $this->votes));
    }

    /**
     * The Personas it names, each once, in ascending order.
     *
     * @return list<int>
     */
    public function personaIds(): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(fn (StudioVoteData $vote): ?int => $vote->personaUserId, $this->votes),
            fn (?int $id): bool => $id !== null,
        )));
        sort($ids);

        return $ids;
    }

    /**
     * Keyed as the editor's fields and its validation are: `votes.3.voteType`.
     *
     * @return array{votes: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return ['votes' => array_map(fn (StudioVoteData $vote): array => $vote->toArray(), $this->votes)];
    }
}
