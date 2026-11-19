<?php

namespace App\Support\Analytics;

/**
 * A version as a traffic period compares it: its number, the share of the
 * period's visitors it was given, and whether an anonymous visitor of it can
 * meet an Email capture step.
 */
final readonly class ComparedVersion
{
    public function __construct(
        public int $id,
        public int $number,
        public int $share,
        public bool $asksForEmail = false,
    ) {}

    public function label(): string
    {
        return 'V'.$this->number;
    }
}
