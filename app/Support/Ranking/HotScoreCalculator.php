<?php

namespace App\Support\Ranking;

use Carbon\CarbonInterface;

final class HotScoreCalculator
{
    public function calculate(
        int $upvotes,
        int $downvotes,
        int $commentsCount,
        CarbonInterface $createdAt,
        CarbonInterface $now,
    ): float {
        return 0.0;
    }
}
