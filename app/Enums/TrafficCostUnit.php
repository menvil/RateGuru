<?php

namespace App\Enums;

/** What a price in a link or a rate is for: one click, or a thousand. */
enum TrafficCostUnit: string
{
    case Click = 'click';
    case Cpm = 'cpm';

    public function label(): string
    {
        return match ($this) {
            self::Click => 'Per click',
            self::Cpm => 'Per 1000',
        };
    }

    /** What a price in this unit is divided by to give the price of one click. */
    public function divisor(): int
    {
        return $this === self::Cpm ? 1000 : 1;
    }
}
