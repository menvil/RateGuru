<?php

namespace App\Enums;

/** Where a visit came from: a paid source — an ad network or a placement — or one of the unpaid channels found by itself. */
enum TrafficChannel: string
{
    case Network = 'network';
    case Placement = 'placement';
    case Shared = 'shared';
    case Referral = 'referral';
    case Search = 'search';
    case Direct = 'direct';
    case Internal = 'internal';

    public function label(): string
    {
        return match ($this) {
            self::Network => 'Ad network',
            self::Placement => 'Banner or link',
            self::Shared => 'Shared result',
            self::Referral => 'Other sites',
            self::Search => 'Search',
            self::Direct => 'Direct',
            self::Internal => 'Inside the site',
        };
    }

    public function isPaid(): bool
    {
        return $this === self::Network || $this === self::Placement;
    }
}
