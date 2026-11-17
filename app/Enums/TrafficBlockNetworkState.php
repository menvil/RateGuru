<?php

namespace App\Enums;

/** Where a block stands with the network: marked in RateGuru only, waiting to go through the API, on the network's blacklist, failed there, or waiting to come off it. */
enum TrafficBlockNetworkState: string
{
    case ByHand = 'by_hand';
    case Pending = 'pending';
    case Applied = 'applied';
    case Failed = 'failed';
    case Removing = 'removing';

    public function label(): string
    {
        return match ($this) {
            self::ByHand => 'Marked by hand',
            self::Pending => 'Waiting for the API',
            self::Applied => 'On the blacklist',
            self::Failed => 'Not applied',
            self::Removing => 'Coming off',
        };
    }
}
