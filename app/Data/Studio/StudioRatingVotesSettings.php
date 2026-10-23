<?php

namespace App\Data\Studio;

/**
 * How many answers one rating question is to get, to each of its options —
 * an option no longer open only as many as it had saved — and when: the
 * first `firstSeconds` after the post, the rest spread over `spreadSeconds`
 * — but an answer given a moment of its own (`moments`) comes then.
 */
final readonly class StudioRatingVotesSettings
{
    /**
     * @param  array<int, int>  $counts  answers by option id
     * @param  array<int, int>  $moments  an answer given a moment of its own, by Persona: seconds after the post
     */
    public function __construct(
        public int $groupId,
        public array $counts = [],
        public int $firstSeconds = 600,
        public int $spreadSeconds = 172_800,
        public array $moments = [],
    ) {}

    public function total(): int
    {
        return array_sum($this->counts);
    }
}
