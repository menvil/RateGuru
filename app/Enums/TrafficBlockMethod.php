<?php

namespace App\Enums;

/** How a block was made: by hand, through the network's API, or by the block rule. */
enum TrafficBlockMethod: string
{
    case Manual = 'manual';
    case Api = 'api';
    case Auto = 'auto';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'By hand',
            self::Api => 'Through the API',
            self::Auto => 'By the block rule',
        };
    }
}
