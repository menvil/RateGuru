<?php

namespace App\Enums;

/**
 * What a segment's accounts per new visitor say against its whole source's
 * (SegmentRules::verdict()). Never stored.
 */
enum SegmentVerdict: string
{
    case TooLittle = 'too_little';
    case Strong = 'strong';
    case Average = 'average';
    case Block = 'block';

    public function label(): string
    {
        return match ($this) {
            self::TooLittle => 'Too little data',
            self::Strong => 'Strong',
            self::Average => 'Average',
            self::Block => 'Block candidate',
        };
    }
}
