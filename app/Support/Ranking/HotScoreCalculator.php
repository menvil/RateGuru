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
        $ageHours = max(0, $createdAt->diffInSeconds($now, false)) / 3600;
        $decay = pow($ageHours + 2.0, 1.5);

        return round($raw / $decay, 6);
    }
}
