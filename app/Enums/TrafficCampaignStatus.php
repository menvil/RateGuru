<?php

namespace App\Enums;

/** A campaign's place in the list: new until set up, active, or archived. */
enum TrafficCampaignStatus: string
{
    case New = 'new';
    case Active = 'active';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Active => 'Active',
            self::Archived => 'Archived',
        };
    }
}
