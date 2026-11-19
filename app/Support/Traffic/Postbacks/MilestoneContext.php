<?php

namespace App\Support\Traffic\Postbacks;

use App\Enums\GameFlowGoal;

/**
 * What a game moment tells the tracker beyond the moment itself: the play it
 * happened in, its game and version, how many rounds that version has (the
 * middle one is Halfway), and, for the goal, which goal it was.
 */
final readonly class MilestoneContext
{
    public function __construct(
        public ?int $attemptId = null,
        public ?int $gameId = null,
        public ?int $versionId = null,
        public int $rounds = 0,
        public ?GameFlowGoal $goal = null,
    ) {}
}
