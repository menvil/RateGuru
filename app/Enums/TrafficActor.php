<?php

namespace App\Enums;

/**
 * Who made a change: an administrator, RateGuru, the network's API, the
 * block rule — or someone in the network's own panel, as a read of the
 * campaigns' lists saw it.
 */
enum TrafficActor: string
{
    case User = 'user';
    case System = 'system';
    case NetworkApi = 'network_api';
    case BlockRule = 'block_rule';
    case NetworkPanel = 'network_panel';

    public function label(): string
    {
        return match ($this) {
            self::User => 'Administrator',
            self::System => site_name(),
            self::NetworkApi => 'Network API',
            self::BlockRule => 'Block rule',
            self::NetworkPanel => 'Network panel',
        };
    }
}
