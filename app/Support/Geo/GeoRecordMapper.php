<?php

namespace App\Support\Geo;

/**
 * Turns a city record of either database into a GeoLocation. Both editions
 * share MaxMind's layout — country.iso_code, subdivisions[].iso_code,
 * city.names.en, location.time_zone — but DB-IP names a city with the
 * district in brackets ("Moscow (Tsentralnyy administrativnyy okrug)") and
 * has no time zone. A country code outside Countries (ZZ, say) is unknown,
 * and so is everything else of a record without a known country.
 */
final class GeoRecordMapper
{
    private const int MAX_CITY = 80;

    private const int MAX_REGION = 8;

    /** @param  array<mixed>|null  $record */
    public static function map(?array $record): GeoLocation
    {
        $code = self::string($record['country']['iso_code'] ?? null);

        if ($code === null || ! Countries::has(strtoupper($code))) {
            return GeoLocation::unknown();
        }

        $code = strtoupper($code);
        $region = self::string($record['subdivisions'][0]['iso_code'] ?? null);
        $city = self::string($record['city']['names']['en'] ?? null);

        if ($city !== null) {
            $city = trim((string) preg_replace('/\s*\([^()]*\)\s*$/u', '', $city));
            $city = $city === '' ? null : mb_substr($city, 0, self::MAX_CITY);
        }

        return new GeoLocation(
            countryCode: $code,
            countryName: Countries::name($code),
            region: $region === null ? null : mb_substr(strtoupper($region), 0, self::MAX_REGION),
            city: $city,
            timeZone: self::string($record['location']['time_zone'] ?? null),
        );
    }

    private static function string(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
