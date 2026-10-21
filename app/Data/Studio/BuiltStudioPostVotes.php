<?php

namespace App\Data\Studio;

/**
 * The votes on a post and its rating questions as BuildStudioPostVotesData
 * made them: every one a Persona is to cast, with the id of the saved vote it
 * changes when it changes one, in the order they are cast — and who took
 * part. What a preview shows is what is saved: nothing is drawn again.
 */
final readonly class BuiltStudioPostVotes
{
    /** @param  list<int>  $voters  the Personas the votes were given to, in the order they were drawn */
    public function __construct(
        public StudioVotesData $votes,
        public array $voters,
    ) {}
}
