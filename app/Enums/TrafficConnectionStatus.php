<?php

namespace App\Enums;

/** A source's connection to its network's API. */
enum TrafficConnectionStatus: string
{
    case Connected = 'connected';
    case NeedsAttention = 'needs_attention';
    case Disconnected = 'disconnected';

    public function label(): string
    {
        return match ($this) {
            self::Connected => 'Connected',
            self::NeedsAttention => 'Needs attention',
            self::Disconnected => 'Not connected',
        };
    }
}
