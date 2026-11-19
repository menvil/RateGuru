<?php

namespace App\Enums;

/**
 * How a condition of a segment holds its values: the click carries one of
 * them, or none of them — a click without the parameter at all among those.
 * Stored as text in a segment's rules.
 */
enum SegmentOperator: string
{
    case In = 'in';
    case Out = 'out';

    /** How the drawer offers it. */
    public function label(): string
    {
        return match ($this) {
            self::In => 'is any of',
            self::Out => 'is not',
        };
    }
}
