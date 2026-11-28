<?php

namespace App\Support\Geo;

/**
 * Names the network an IP address belongs to. Never throws and never
 * downloads anything: an address that is not public, a missing database or
 * list, or a broken file all answer what is left — NetworkInfo::unknown()
 * when nothing is.
 */
interface NetworkLookup
{
    public function lookup(?string $ip): NetworkInfo;
}
