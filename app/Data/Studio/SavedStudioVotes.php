<?php

namespace App\Data\Studio;

use App\Models\StudioPlanVote;
use Illuminate\Support\Collection;

/**
 * A Draft's votes as a save left them: every vote with the id it keeps from
 * now on, in the order they are cast, the plan's new version, and the id
 * each new vote's key was given.
 */
final readonly class SavedStudioVotes
{
    /**
     * @param  Collection<int, StudioPlanVote>  $votes  by their moment, then their place, then id
     * @param  array<string, int>  $ids  the id each key of the save was given
     */
    public function __construct(
        public int $lockVersion,
        public Collection $votes,
        public array $ids,
    ) {}
}
