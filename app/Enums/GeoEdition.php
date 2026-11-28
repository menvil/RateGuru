<?php

namespace App\Enums;

/**
 * The MaxMind DB files the site keeps under storage/geoip. Two are city-level
 * databases that place visitors: which one serves is the administrator's
 * choice, and the other stands in while the chosen one cannot. The third
 * names the network an address belongs to — its ASN — and serves alongside
 * whichever city database places the visitor, standing in for nothing (see
 * docs/architecture/geo.md).
 */
enum GeoEdition: string
{
    case GeoLite2City = 'geolite2_city';
    case DbipCityLite = 'dbip_city_lite';
    case DbipAsnLite = 'dbip_asn_lite';

    public function label(): string
    {
        return match ($this) {
            self::GeoLite2City => 'GeoLite2 City',
            self::DbipCityLite => 'DB-IP City Lite',
            self::DbipAsnLite => 'DB-IP ASN Lite',
        };
    }

    public function maker(): string
    {
        return match ($this) {
            self::GeoLite2City => 'MaxMind',
            self::DbipCityLite, self::DbipAsnLite => 'DB-IP',
        };
    }

    /** Whether it places an address — a country, region and city — rather than naming its network. */
    public function isCity(): bool
    {
        return $this !== self::DbipAsnLite;
    }

    /** The edition that stands in for this one: the other city database, and none for the networks'. */
    public function other(): self
    {
        return match ($this) {
            self::GeoLite2City => self::DbipCityLite,
            self::DbipCityLite => self::GeoLite2City,
            self::DbipAsnLite => self::DbipAsnLite,
        };
    }

    /**
     * The editions an administrator may choose to place visitors with.
     *
     * @return list<self>
     */
    public static function cities(): array
    {
        return array_values(array_filter(self::cases(), fn (self $edition): bool => $edition->isCity()));
    }
}
