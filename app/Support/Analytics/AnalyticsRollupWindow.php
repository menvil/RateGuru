<?php

namespace App\Support\Analytics;

use Carbon\CarbonImmutable;

/**
 * The network days whose visits still change by themselves — arriving in
 * every source's time zone, opened, continued, sent on through a token or a
 * form, settled — so analytics:refresh-visits reads them again every minute
 * rather than wait to be told: from the day before the earliest day any time
 * zone is on (twelve hours behind UTC) to the latest one (fourteen ahead).
 * A change to an older day is told (MarkAnalyticsDayAction). Pure.
 */
final class AnalyticsRollupWindow
{
    /** Every so many minutes the whole of the days is read, not only today's on. */
    public const int WHOLE_MINUTES = 5;

    /** @return list<string> the days, Y-m-d, the earliest first */
    public static function days(CarbonImmutable $now): array
    {
        $now = $now->utc();
        $day = $now->subHours(12)->subDay()->startOfDay();
        $last = $now->addHours(14)->startOfDay();
        $days = [];

        for (; $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
            $days[] = $day->toDateString();
        }

        return $days;
    }

    /** Whether a day is one of those read again every minute. */
    public static function covers(string $day, CarbonImmutable $now): bool
    {
        return in_array($day, self::days($now), true);
    }

    /**
     * Whether a change to a day made now is read without being told: the day
     * is among them now and still is when the whole of them has been read
     * twice more, at most twice WHOLE_MINUTES away — one whole read missed, a
     * run of the minute before still busy or one that failed, leaves the
     * next to read it. The earliest day leaves the window as time passes, so
     * one among them now can be gone before those reads.
     */
    public static function readsChangeMadeAt(string $day, CarbonImmutable $now): bool
    {
        return self::covers($day, $now) && self::covers($day, $now->addMinutes(2 * self::WHOLE_MINUTES));
    }
}
