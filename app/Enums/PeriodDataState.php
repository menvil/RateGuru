<?php

namespace App\Enums;

/**
 * Whether a traffic period's versions can be compared yet
 * (PeriodComparison::state()): one version has nothing to be compared with;
 * with more, nobody was sent to any yet, too early while a version is short of
 * what a comparison needs on the primary metric, or enough data once every
 * version has it. Growth → Experiments and a game's Analytics tab draw the
 * same badge from it. Never stored.
 */
enum PeriodDataState: string
{
    case Single = 'single';
    case Empty = 'empty';
    case Early = 'early';
    case Enough = 'enough';

    public function label(): string
    {
        return match ($this) {
            self::Single => 'One version',
            self::Empty => 'No visitors yet',
            self::Early => 'Too early',
            self::Enough => 'Enough data',
        };
    }

    /** The tone of its badge. */
    public function tone(): string
    {
        return match ($this) {
            self::Single => 'outline',
            self::Empty => 'neutral',
            self::Early => 'warning',
            self::Enough => 'success',
        };
    }
}
