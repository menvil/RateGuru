<?php

namespace App\Events\Games;

use App\Enums\GameFlowGoal;
use App\Enums\GameMilestone;
use App\Support\Games\Play\GameVisitor;

/**
 * A play reached a moment the traffic tracker counts — or its flow's goal,
 * when `goal` is set. Raised synchronously inside the transaction of the
 * action that recorded the moment, so what the tracker writes for it is
 * committed or rolled back with the game's own record.
 */
final readonly class GameMilestoneReached
{
    public function __construct(
        public GameMilestone|string $milestone,
        public int $gameId,
        public int $versionId,
        public int $attemptId,
        public ?int $visitId,
        public GameVisitor $visitor,
        public int $roundCount,
        public ?GameFlowGoal $goal = null,
    ) {}

    public function isGoal(): bool
    {
        return $this->goal !== null;
    }
}
