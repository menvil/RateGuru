<?php

namespace App\Enums;

/** Where a visit's spend came from. */
enum TrafficSpendBasis: string
{
    case None = 'none';
    case ClickCost = 'click_cost';
    case Rate = 'rate';
    case CampaignPrice = 'campaign_price';
    case Fixed = 'fixed';
    case Api = 'api';
    case Csv = 'csv';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::None => 'None',
            self::ClickCost => 'Price in the click',
            self::Rate => 'Your rate',
            self::CampaignPrice => 'Campaign price',
            self::Fixed => 'Fixed price',
            self::Api => 'Network API',
            self::Csv => 'Imported',
            self::Manual => 'Typed in',
        };
    }
}
