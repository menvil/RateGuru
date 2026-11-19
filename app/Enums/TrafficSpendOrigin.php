<?php

namespace App\Enums;

/** Where a day's spend figure came from. */
enum TrafficSpendOrigin: string
{
    case Api = 'api';
    case Csv = 'csv';
    case Manual = 'manual';
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::Api => 'Network API',
            self::Csv => 'Imported',
            self::Manual => 'Typed in',
            self::Fixed => 'Fixed price',
        };
    }
}
