<?php

namespace App\Enums;

/** A source's state. Clicks are recorded and credited in every one; only an active source has postbacks queued, and an ended one is no longer synced. */
enum TrafficSourceStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Ended = 'ended';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::Ended => 'Ended',
        };
    }
}
