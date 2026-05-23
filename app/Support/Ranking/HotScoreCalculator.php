<?php

namespace App\Support\Ranking;

use Carbon\CarbonInterface;

final class HotScoreCalculator
{
    private const BASE_SCORE = 1.0;
    private const COMMENT_WEIGHT = 0.5;
    private const AGE_OFFSET_HOURS = 2.0;
    private const GRAVITY = 1.5;

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
        $commentContribution = $commentsCount * self::COMMENT_WEIGHT;
        $raw = self::BASE_SCORE + $netVotes + $commentContribution;
        $ageHours = max(0, $createdAt->diffInSeconds($now, false)) / 3600;
        $decay = pow($ageHours + self::AGE_OFFSET_HOURS, self::GRAVITY);

        return round($raw / $decay, 6);
    }
}
