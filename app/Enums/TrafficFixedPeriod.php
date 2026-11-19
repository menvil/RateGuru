<?php

namespace App\Enums;

/** How often a placement's fixed price is paid. */
enum TrafficFixedPeriod: string
{
    case Once = 'once';
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    public function label(): string
    {
        return match ($this) {
            self::Once => 'Once',
            self::Day => 'A day',
            self::Week => 'A week',
            self::Month => 'A month',
        };
    }
}
