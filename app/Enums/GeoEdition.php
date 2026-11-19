<?php

namespace App\Enums;

/**
 * The IP location databases the site can place visitors with. Both are
 * city-level MaxMind DB files; which one serves is the administrator's
 * choice, and the other stands in while the chosen one cannot (see
 * docs/architecture/geo.md).
 */
enum GeoEdition: string
{
    case GeoLite2City = 'geolite2_city';
    case DbipCityLite = 'dbip_city_lite';

    public function label(): string
    {
        return match ($this) {
            self::GeoLite2City => 'GeoLite2 City',
            self::DbipCityLite => 'DB-IP City Lite',
        };
    }

    public function maker(): string
    {
        return match ($this) {
            self::GeoLite2City => 'MaxMind',
            self::DbipCityLite => 'DB-IP',
        };
    }

    /** The edition that stands in for this one. */
    public function other(): self
    {
        return match ($this) {
            self::GeoLite2City => self::DbipCityLite,
            self::DbipCityLite => self::GeoLite2City,
        };
    }
}
