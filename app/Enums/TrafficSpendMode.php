<?php

namespace App\Enums;

/** How a source — or one of its campaigns — counts what it spent. A campaign uses price in place of rate, and no campaign uses fixed or none. */
enum TrafficSpendMode: string
{
    case Api = 'api';
    case ClickCost = 'click_cost';
    case Rate = 'rate';
    case Price = 'price';
    case Fixed = 'fixed';
    case Manual = 'manual';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Api => 'Network API',
            self::ClickCost => 'Price in each click',
            self::Rate => 'Your rate',
            self::Price => 'Campaign price',
            self::Fixed => 'Fixed price',
            self::Manual => 'Typed in or imported',
            self::None => 'Free',
        };
    }
}
