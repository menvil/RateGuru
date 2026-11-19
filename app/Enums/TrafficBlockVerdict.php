<?php

namespace App\Enums;

/** What the block rule found. */
enum TrafficBlockVerdict: string
{
    case Bots = 'bots';
    case Losing = 'losing';
    case WeakStart = 'weak_start';

    public function label(): string
    {
        return match ($this) {
            self::Bots => 'Likely bots',
            self::Losing => 'Losing',
            self::WeakStart => 'Weak start',
        };
    }
}
