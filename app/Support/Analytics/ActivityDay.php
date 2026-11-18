<?php

namespace App\Support\Analytics;

use Carbon\CarbonImmutable;

/**
 * Which day of an account's life a moment falls on: whole calendar days in
 * UTC from the day it signed up, that day being day 0 — however late on it
 * the account signed up and however early on the later day it came back.
 * Worked out here rather than in SQL, where date arithmetic is written
 * differently on every engine.
 */
final class ActivityDay
{
    private const int SECONDS_PER_DAY = 86_400;

    /** The UTC day a moment falls on, as `user_activity_days.day` keeps it. */
    public static function of(CarbonImmutable $at): string
    {
        return $at->utc()->format('Y-m-d');
    }

    /** The day of the account's life the moment falls on; negative for a moment before the day it signed up. */
    public static function number(CarbonImmutable $signedUpAt, CarbonImmutable $at): int
    {
        $from = $signedUpAt->utc()->startOfDay()->getTimestamp();
        $to = $at->utc()->startOfDay()->getTimestamp();

        // UTC has no daylight saving: every day is exactly as long.
        return intdiv($to - $from, self::SECONDS_PER_DAY);
    }

    /** Whether doing something on this day of the account's life activates it: day 1 up to the last day given, never the day it signed up. */
    public static function activates(?int $dayNumber, int $activationDays): bool
    {
        return $dayNumber !== null && $dayNumber >= 1 && $dayNumber <= $activationDays;
    }
}
