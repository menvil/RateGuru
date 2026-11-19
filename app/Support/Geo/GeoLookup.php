<?php

namespace App\Support\Geo;

/**
 * Places an IP address. Never throws and never downloads anything: an address
 * that is not public, a missing database or a broken file all answer
 * GeoLocation::unknown().
 */
interface GeoLookup
{
    public function lookup(?string $ip): GeoLocation;
}
