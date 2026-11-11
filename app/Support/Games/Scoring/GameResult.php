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
}
