<?php

namespace App\Support\Geo;

/**
 * Where an IP address is, as far as the local database knows: a country code
 * from Countries, a region (the first subdivision's ISO code), a city name in
 * English and a time zone. Any of them may be unknown; without a country the
 * rest is unknown too.
 */
final readonly class GeoLocation
{
    public function __construct(
        public ?string $countryCode = null,
        public ?string $countryName = null,
        public ?string $region = null,
        public ?string $city = null,
        public ?string $timeZone = null,
    ) {}

    public static function unknown(): self
    {
        return new self;
    }

    public function isKnown(): bool
    {
        return $this->countryCode !== null;
    }
}
