<?php

namespace App\Data\Studio;

/**
 * What the Comment votes editor asks for, before any vote is made of it: who
 * votes — `random`, as many Personas as `voterCount` drawn from the active
 * ones, or `manual`, the ones chosen in `voterIds` — and, for each prepared
 * comment or reply, how many vote it up and down and when
 * (StudioDiscussionVoteTargetSettings).
 *
 * `seed` fixes every draw made of it, so the same settings give the same
 * votes; a new one is a new draw. `keepSaved` prefers the votes a plan has
 * saved — the same Persona on the same comment, so its row is changed rather
 * than replaced — until a new draw is asked for.
 *
 * It is never stored: a plan keeps the votes made of it, not the recipe.
 * Nothing here is checked; BuildStudioDiscussionVotesData is.
 */
final readonly class StudioDiscussionVotesSettings
{
    /**
     * @param  list<int>  $voterIds  the Personas chosen by hand, in the order chosen
     * @param  array<int, StudioDiscussionVoteTargetSettings>  $targets  keyed by discussion node id
     */
    public function __construct(
        public string $voterMode = 'random',
        public int $voterCount = 0,
        public array $voterIds = [],
        public array $targets = [],
        public int $seed = 1,
        public bool $keepSaved = true,
    ) {}

    /** Whether the voters are the ones chosen by hand rather than drawn. */
    public function isManual(): bool
    {
        return $this->voterMode === 'manual';
    }
}
