<?php

namespace App\Enums;

/** The two devices visits are counted by; tablets count as mobile. */
enum TrafficDevice: string
{
    case Mobile = 'mobile';
    case Desktop = 'desktop';

    public function label(): string
    {
        return match ($this) {
            self::Mobile => 'Mobile',
            self::Desktop => 'Desktop',
        };
    }
}
