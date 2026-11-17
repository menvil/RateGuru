<?php

namespace App\Enums;

/** What a parameter of a source's link carries. */
enum TrafficLinkParam: string
{
    case Click = 'click';
    case Campaign = 'campaign';
    case Site = 'site';
    case Zone = 'zone';
    case Creative = 'creative';
    case Cost = 'cost';

    public function label(): string
    {
        return match ($this) {
            self::Click => 'Click ID',
            self::Campaign => 'Campaign',
            self::Site => 'Site',
            self::Zone => 'Zone',
            self::Creative => 'Creative',
            self::Cost => 'Cost',
        };
    }
}
