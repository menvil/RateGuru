<?php

namespace App\Enums;

/** When a step's worth set by hand applies: always, or only while the slice has too few accounts of its own. */
enum TrafficStepValueMode: string
{
    case Always = 'always';
    case Thin = 'thin';

    public function label(): string
    {
        return match ($this) {
            self::Always => 'Always',
            self::Thin => 'Only while data is thin',
        };
    }
}
