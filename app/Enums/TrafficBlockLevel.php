<?php

namespace App\Enums;

/** What of a network is blocked: a publisher's site, or one of its zones. */
enum TrafficBlockLevel: string
{
    case Site = 'site';
    case Zone = 'zone';

    public function label(): string
    {
        return match ($this) {
            self::Site => 'Site',
            self::Zone => 'Zone',
        };
    }
}
