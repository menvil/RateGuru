<?php

namespace App\Enums;

/** Where a campaign stands in the administrator's own way of buying; a label only, which reports split results by. */
enum TrafficCampaignStage: string
{
    case Test = 'test';
    case Optimise = 'optimise';
    case Scale = 'scale';
    case Paused = 'paused';

    public function label(): string
    {
        return match ($this) {
            self::Test => 'Testing',
            self::Optimise => 'Optimising',
            self::Scale => 'Scaling',
            self::Paused => 'Paused',
        };
    }
}
