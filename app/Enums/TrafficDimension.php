<?php

namespace App\Enums;

/**
 * What a traffic report can be split by — a level of the tree, or the way
 * Sign-ups and confirmation splits its sign-ups — and the visit columns each
 * groups on: the only column names a report query ever writes into SQL.
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
    case SignupMethod = 'signup_method';
    case SignupPlace = 'signup_place';

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
            self::SignupMethod => ['signup_method'],
            self::SignupPlace => ['signup_place'],
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
            self::SignupMethod => 'Sign-up method',
            self::SignupPlace => 'Where they signed up',
        };
    }
}
