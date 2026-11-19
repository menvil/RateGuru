<?php

namespace App\Enums;

/** A paid source: an ad network whose links carry its click id, or a banner or link bought at a fixed price. */
enum TrafficSourceType: string
{
    case Network = 'network';
    case Placement = 'placement';

    public function label(): string
    {
        return match ($this) {
            self::Network => 'Ad network',
            self::Placement => 'Banner or link',
        };
    }
}
