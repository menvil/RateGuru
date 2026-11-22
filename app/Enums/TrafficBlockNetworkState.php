<?php

namespace App\Enums;

/**
 * Where a block stands with the network: marked in RateGuru only, waiting to
 * go through the API, on the list of every campaign it is for, on some of
 * them only, on none — refused there — taken off them in the network's
 * panel since, or waiting to come off them. Where it stands on each
 * campaign's list is kept beside it (TrafficBlockListing).
 */
enum TrafficBlockNetworkState: string
{
    case ByHand = 'by_hand';
    case Pending = 'pending';
    case Applied = 'applied';
    case Partial = 'partial';
    case Failed = 'failed';
    case TakenOff = 'taken_off';
    case Removing = 'removing';

    public function label(): string
    {
        return match ($this) {
            self::ByHand => 'Marked by hand',
            self::Pending => 'Waiting for the API',
            self::Applied => 'On the blacklist',
            self::Partial => 'On some campaigns’ lists',
            self::Failed => 'Not applied',
            self::TakenOff => 'Taken off on the network',
            self::Removing => 'Coming off',
        };
    }
}
