<?php

namespace App\Support\Analytics;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The UTC hours a traffic period's funnel is summed by
 * (analytics_period_hours), each the hour its assignments were made in, and
 * when an hour can be counted. An hour is open while it or the hour after it
 * is the current one: what plays its assignments start, finish and lead to
 * mostly happens then, so a change to an open hour is not told — the funnel
 * reads open hours from the assignments themselves. Once that is over, a
 * change is told (MarkAnalyticsPeriodHourAction), and an hour is counted once
 * every change made while it was open is in: SETTLE_MINUTES after it closed,
 * more than any transaction takes from making a change to keeping it. Pure.
 */
final class PeriodFunnelHours
{
    /** How long after an hour begins a change to its assignments is still not told. */
    public const int OPEN_MINUTES = 120;

    /** How long after an hour closes it is counted: what a change made while it was open can take to be kept. */
    public const int SETTLE_MINUTES = 10;

    /** The hour a moment is in, UTC. */
    public static function hourOf(CarbonInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)->utc()->startOfHour();
    }

    /** Whether a change made now to an assignment of the hour goes untold: the hour is still open. */
    public static function isOpen(CarbonImmutable $hour, CarbonImmutable $now): bool
    {
        return $now->utc()->lessThan($hour->addMinutes(self::OPEN_MINUTES));
    }

    /** The last hour that can be counted now: closed, and settled since. */
    public static function lastCountable(CarbonImmutable $now): CarbonImmutable
    {
        return self::hourOf($now->utc()->subMinutes(self::OPEN_MINUTES + self::SETTLE_MINUTES));
    }
}
