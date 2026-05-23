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
        $upvotes = max(0, $upvotes);
        $downvotes = max(0, $downvotes);
        $commentsCount = max(0, $commentsCount);

        $netVotes = max(0, $upvotes - $downvotes);
        $raw = 1.0 + $netVotes;

        return round($raw, 6);
    }
}
