<?php

namespace App\Enums;

/**
 * What Growth → Analytics can split its people by or narrow them to, and the
 * visit columns each reads: the only column names an Analytics query ever
 * writes into SQL. Every one is a column of the visit itself — where its
 * session came from is copied onto each of its visits as it is recorded —
 * so no read joins the visit its session began with. Never stored.
 */
enum AnalyticsDimension: string
{
    case Source = 'source';
    case Campaign = 'campaign';
    case Creative = 'creative';
    case Landing = 'landing';
    case Device = 'device';
    case Country = 'country';
    case City = 'city';
    case Game = 'game';
    case Version = 'version';

    /** @return list<string> the columns a split groups on, the first naming it */
    public function columns(): array
    {
        return match ($this) {
            self::Source => ['source_key'],
            self::Campaign => ['campaign_key'],
            self::Creative => ['creative_key'],
            self::Landing => ['landing_key'],
            self::Device => ['device'],
            self::Country => ['country'],
            self::City => ['city', 'country'],
            self::Game => ['game_id'],
            self::Version => ['version_id'],
        };
    }

    /** Whether a column is one a dimension reads. */
    public static function reads(string $column): bool
    {
        foreach (self::cases() as $dimension) {
            if (in_array($column, $dimension->columns(), true)) {
                return true;
            }
        }

        return false;
    }

    public function label(): string
    {
        return match ($this) {
            self::Source => 'Source',
            self::Campaign => 'Campaign',
            self::Creative => 'Creative',
            self::Landing => 'Landing page',
            self::Device => 'Device',
            self::Country => 'Country',
            self::City => 'City',
            self::Game => 'Game',
            self::Version => 'Game version',
        };
    }
}
