<?php

namespace App\Enums;

/**
 * What a condition of a paid source's segment reads off each of its clicks,
 * and the visit column it reads: the only column names a segment ever writes
 * into SQL. A campaign is named in the drawer by its id in the network and
 * kept as the campaign's own id; a device by TrafficDevice; a country by its
 * code. Stored as text in a segment's rules.
 */
enum SegmentParameter: string
{
    case Site = 'site';
    case Zone = 'zone';
    case Campaign = 'campaign';
    case Creative = 'creative';
    case Device = 'device';
    case Country = 'country';

    public function column(): string
    {
        return match ($this) {
            self::Site => 'site_ref',
            self::Zone => 'zone_ref',
            self::Campaign => 'campaign_id',
            self::Creative => 'creative_ref',
            self::Device => 'device',
            self::Country => 'country',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Site => 'Site',
            self::Zone => 'Zone',
            self::Campaign => 'Campaign',
            self::Creative => 'Creative',
            self::Device => 'Device',
            self::Country => 'Country',
        };
    }

    /** What the drawer's field for its values suggests typing. */
    public function hint(): string
    {
        return match ($this) {
            self::Site => 'Site IDs, e.g. 1182, 2210',
            self::Zone => 'Zone IDs, e.g. 44718',
            self::Campaign => 'Campaign IDs in the network, e.g. 5521904',
            self::Creative => 'Creative or variation IDs',
            self::Device => '',
            self::Country => 'Country codes, e.g. DE, AT',
        };
    }
}
