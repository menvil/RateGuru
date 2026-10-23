<?php

namespace App\Data\Studio;

/**
 * What the Post votes editor asks for, before any vote is made of it: who
 * votes — `random`, as many Personas as `voterCount` drawn from the active
 * ones, or `manual`, the ones chosen in `voterIds` — how many vote the post
 * up and down, how many answers each rating question gets to each of its
 * options, and when the voters come, relative to the post: the first after
 * `firstSeconds`, the rest spread over `spreadSeconds`, each casting all its
 * votes in one visit — but a vote given a moment of its own (`postMoments`,
 * by Persona, in seconds after the post) comes then.
 *
 * `seed` fixes every draw made of it, so the same settings give the same
 * votes; a new one is a new draw. `keepSaved` prefers the votes a plan has
 * saved — the same Persona on the same target, so its row is changed rather
 * than replaced — until a new draw is asked for.
 *
 * It is never stored: a plan keeps the votes made of it, not the recipe.
 * Nothing here is checked; BuildStudioPostVotesData is.
 */
final readonly class StudioPostVotesSettings
{
    /**
     * @param  list<int>  $voterIds  the Personas chosen by hand, in the order chosen
     * @param  array<int, int>  $postMoments  a vote on the post given a moment of its own, by Persona: seconds after the post
     * @param  array<int, StudioRatingVotesSettings>  $ratings  keyed by rating group id
     */
    public function __construct(
        public string $voterMode = 'random',
        public int $voterCount = 0,
        public array $voterIds = [],
        public int $up = 0,
        public int $down = 0,
        public int $firstSeconds = 300,
        public int $spreadSeconds = 86_400,
        public array $postMoments = [],
        public array $ratings = [],
        public int $seed = 1,
        public bool $keepSaved = true,
    ) {}

    /** Whether the voters are the ones chosen by hand rather than drawn. */
    public function isManual(): bool
    {
        return $this->voterMode === 'manual';
    }
}
