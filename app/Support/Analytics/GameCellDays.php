<?php

namespace App\Support\Analytics;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The UTC days a game's people are counted by (analytics_game_cells), and
 * when a day is counted how. A day is open until an hour after it ends: a
 * play started late in it mostly finishes then, and nothing tells of what an
 * open day's plays go on to do — so an open day is counted up to a moment,
 * every GENERATION_MINUTES, its views and plays begun before the moment and
 * what came of them before it, and a game's figures read what came since
 * from the events and attempts themselves. Once a day closes, every writer of
 * what a game's people count of it tells it (MarkAnalyticsGameCellsAction),
 * and it is counted whole once every change made while it was open is in:
 * SETTLE_MINUTES after it closed, more than any transaction takes from
 * making a change to keeping it. The moment an open day is counted up to is
 * as long before now, for the same reason. Pure.
 */
final class GameCellDays
{
    /** How long after a day ends a change to its plays is still not told. */
    public const int OPEN_MINUTES = 60;

    /** How long a change can take from being made to being kept: what a moment counted up to, and a day counted whole, wait for. */
    public const int SETTLE_MINUTES = 10;

    /** How often the open days are counted up to a newer moment. */
    public const int GENERATION_MINUTES = 5;

    /** How much older than the newest a count of the open days up to a moment is kept, and the one just before the newest whatever its age: longer than a read takes from learning the moment to reading the cells. */
    public const int KEEP_MINUTES = 10;

    /** The UTC day of a moment, Y-m-d. */
    public static function dayOf(CarbonInterface $moment): string
    {
        return CarbonImmutable::instance($moment)->utc()->toDateString();
    }

    /** The day after a day, Y-m-d. */
    public static function after(string $day): string
    {
        return CarbonImmutable::parse($day, 'UTC')->addDay()->toDateString();
    }

    /** Whether a change made now to a play begun on the day goes untold: the day is still open. */
    public static function isOpen(string $day, CarbonImmutable $now): bool
    {
        return $now->utc()->lessThan(CarbonImmutable::parse($day, 'UTC')->addDay()->addMinutes(self::OPEN_MINUTES));
    }

    /** The last day that can be counted whole now: closed, and settled since. */
    public static function lastClosable(CarbonImmutable $now): string
    {
        return $now->utc()->subMinutes(self::OPEN_MINUTES + self::SETTLE_MINUTES)->startOfDay()->subDay()->toDateString();
    }

    /** The moment the open days can be counted up to now: every change made before it is kept. */
    public static function momentOf(CarbonImmutable $now): CarbonImmutable
    {
        return $now->utc()->startOfSecond()->subMinutes(self::SETTLE_MINUTES);
    }
}
