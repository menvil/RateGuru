<?php

namespace App\Enums;

/** The networks RateGuru has an API connector for. */
enum TrafficConnector: string
{
    case PopAds = 'popads';
    case ExoClick = 'exoclick';

    public function label(): string
    {
        return match ($this) {
            self::PopAds => 'PopAds',
            self::ExoClick => 'ExoClick',
        };
    }

    /** The time zone the network's spend comes in by day: a source it reads for files its visits by the same days. */
    public function reportsIn(): string
    {
        return match ($this) {
            self::PopAds => 'UTC',
            self::ExoClick => 'America/New_York',
        };
    }
}
