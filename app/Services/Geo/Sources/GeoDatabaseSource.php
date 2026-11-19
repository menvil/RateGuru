<?php

namespace App\Services\Geo\Sources;

use App\Support\Geo\Update\GeoEditionSpec;
use App\Support\Geo\Update\GeoRelease;

/** Somewhere an edition's latest build can be found and downloaded from. */
interface GeoDatabaseSource
{
    public function key(): string;

    /** The latest build, by its exact-version address; throws GeoUpdateException when the source cannot say. */
    public function discover(GeoEditionSpec $spec): GeoRelease;
}
