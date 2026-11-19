<?php

namespace App\Enums;

/** What a campaign history line is about. */
enum TrafficCampaignEventKind: string
{
    case Created = 'created';
    case Stage = 'stage';
    case List = 'list';
    case Bid = 'bid';
    case Budget = 'budget';
    case Targeting = 'targeting';
    case Archive = 'archive';
    case Spend = 'spend';
    case Target = 'target';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Created',
            self::Stage => 'Stage',
            self::List => 'Lists',
            self::Bid => 'Price',
            self::Budget => 'Budget',
            self::Targeting => 'Targeting',
            self::Archive => 'Archive',
            self::Spend => 'Spend',
            self::Target => 'Target',
        };
    }
}
