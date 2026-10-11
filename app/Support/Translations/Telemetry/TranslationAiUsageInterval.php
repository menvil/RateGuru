<?php

namespace App\Support\Translations\Telemetry;

use Carbon\CarbonImmutable;

/**
 * The width of one point of a Translation AI usage timeline, in UTC.
 */
enum TranslationAiUsageInterval: string
{
    case Hour = 'hour';
    case Day = 'day';

    /** The start of the point an instant falls in. */
    public function start(CarbonImmutable $instant): CarbonImmutable
    {
        $instant = $instant->utc();

        return match ($this) {
            self::Hour => $instant->startOfHour(),
            self::Day => $instant->startOfDay(),
        };
    }

    public function next(CarbonImmutable $start): CarbonImmutable
    {
        return match ($this) {
            self::Hour => $start->addHour(),
            self::Day => $start->addDay(),
        };
    }

    /** How a point is keyed, as PHP's date() formats it — the same key the database groups by. */
    public function keyFormat(): string
    {
        return match ($this) {
            self::Hour => 'Y-m-d H',
            self::Day => 'Y-m-d',
        };
    }
}
