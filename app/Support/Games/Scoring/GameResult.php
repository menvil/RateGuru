<?php

namespace App\Support\Games\Scoring;

use App\Support\Games\Snapshot\SnapshotProfile;

/** A finished attempt's result, worked out from its answers and its version. */
final readonly class GameResult
{
    public function __construct(
        public int $matches,
        public int $scored,
        public int $score,
        public int $maxScore,
        public ?SnapshotProfile $profile,
    ) {}

    /** How often the player matched, as a whole percentage of the scored rounds. */
    public function agreement(): int
    {
        return $this->scored === 0 ? 0 : (int) round($this->matches * 100 / $this->scored);
    }
}
