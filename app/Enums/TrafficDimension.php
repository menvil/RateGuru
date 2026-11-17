<?php

namespace App\Enums;

/**
 * What a traffic report can be split by, and the visit columns each level
 * groups on — the only column names a report query ever writes into SQL.
 */
enum TrafficDimension: string
{
    case Type = 'type';
    case Source = 'source';
    case Campaign = 'campaign';
    case Site = 'site';
    case Zone = 'zone';
    case Device = 'device';
    case Country = 'country';
    case City = 'city';
    case Game = 'game';
    case Version = 'version';

    /** @return list<string> the columns a level groups on, the first naming it */
    public function columns(): array
    {
        return match ($this) {
            self::Type => ['channel'],
            self::Source => ['source_id', 'channel'],
            self::Campaign => ['campaign_id', 'source_id'],
            self::Site => ['site_ref', 'source_id'],
            self::Zone => ['zone_ref', 'source_id'],
            self::Device => ['device'],
            self::Country => ['country'],
            self::City => ['city', 'country'],
            self::Game => ['game_id'],
            self::Version => ['version_id', 'game_id'],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Type => 'Traffic type',
            self::Source => 'Source',
            self::Campaign => 'Campaign',
            self::Site => 'Site ID',
            self::Zone => 'Zone ID',
            self::Device => 'Device',
            self::Country => 'Country',
            self::City => 'City',
            self::Game => 'Game',
            self::Version => 'Game version',
        };
    }
}
