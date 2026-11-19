<?php

namespace App\Enums;

/** Who made a change. */
enum TrafficActor: string
{
    case User = 'user';
    case System = 'system';
    case NetworkApi = 'network_api';
    case BlockRule = 'block_rule';

    public function label(): string
    {
        return match ($this) {
            self::User => 'Administrator',
            self::System => 'RateGuru',
            self::NetworkApi => 'Network API',
            self::BlockRule => 'Block rule',
        };
    }
}
