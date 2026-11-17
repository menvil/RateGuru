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
}
