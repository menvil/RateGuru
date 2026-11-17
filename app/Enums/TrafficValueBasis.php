<?php

namespace App\Enums;

/** How a step's worth was arrived at. */
enum TrafficValueBasis: string
{
    case Measured = 'measured';
    case Leaning = 'leaning';
    case Manual = 'manual';
    case Outcome = 'outcome';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Measured => 'Worked out',
            self::Leaning => 'Leaning on the level above',
            self::Manual => 'Set by hand',
            self::Outcome => 'Outcome price',
            self::None => 'Nothing',
        };
    }
}
