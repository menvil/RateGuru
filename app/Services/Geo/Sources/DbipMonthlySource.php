<?php

namespace App\Services\Geo\Sources;

use App\Enums\GeoUpdateFailure;
use App\Exceptions\Geo\GeoUpdateException;
use App\Services\Geo\GeoHttp;
use App\Support\Geo\Update\GeoEditionSpec;
use App\Support\Geo\Update\GeoRelease;
use Carbon\CarbonImmutable;

/**
 * DB-IP's own download of the free City Lite database, one file a month:
 * this month's, or last month's until this month's is out. DB-IP publishes no
 * machine-readable checksum, so the file is held to the size its server
 * announces, the gzip check of its own end and the database checks.
 */
final readonly class DbipMonthlySource implements GeoDatabaseSource
{
    public function __construct(private GeoHttp $http) {}

    public function key(): string
    {
        return 'dbip';
    }

    public function discover(GeoEditionSpec $spec): GeoRelease
    {
        $month = CarbonImmutable::now('UTC')->startOfMonth();

        foreach ([$month, $month->subMonth()] as $candidate) {
            $url = 'https://download.db-ip.com/free/dbip-city-lite-'.$candidate->format('Y-m').'.mmdb.gz';
            $response = $this->http->head($url, $this->key());

            if ($response->status() === 404) {
                continue;
            }

            $size = (int) $response->header('Content-Length');

            if ($response->status() !== 200 || $size <= 0) {
                break;
            }

            $etag = trim($response->header('ETag'), '"W/ ');

            return new GeoRelease(
                source: $this->key(),
                token: $candidate->format('Y-m').':'.($etag !== '' ? $etag : $size),
                url: $url,
                format: 'gz',
                size: $size,
            );
        }

        throw GeoUpdateException::because(GeoUpdateFailure::DiscoveryFailed, $this->key());
    }
}
