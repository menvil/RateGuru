<?php

namespace App\Enums;

/**
 * How a block was made: by hand, through the network's API, by the block
 * rule — or in the network's own panel, not by RateGuru, as a read of the
 * campaigns' lists found it.
 */
enum TrafficBlockMethod: string
{
    case Manual = 'manual';
    case Api = 'api';
    case Auto = 'auto';
    case Panel = 'panel';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'By hand',
            self::Api => 'Through the API',
            self::Auto => 'By the block rule',
            self::Panel => 'In the network’s panel',
        };
    }
}
