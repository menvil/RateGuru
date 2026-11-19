<?php

namespace App\Services\Geo\Sources;

use App\Enums\GeoUpdateFailure;
use App\Exceptions\Geo\GeoUpdateException;
use App\Services\Geo\GeoHttp;
use App\Support\Geo\Update\GeoEditionSpec;
use App\Support\Geo\Update\GeoRelease;

/**
 * jsDelivr's mirror of the npm package: the latest version from its data API,
 * the file's SHA-256 and size from the version's file list, and the file
 * itself from the exact-version address, which jsDelivr never changes.
 */
final readonly class JsdelivrFileSource implements GeoDatabaseSource
{
    public function __construct(private GeoHttp $http) {}

    public function key(): string
    {
        return 'jsdelivr';
    }

    public function discover(GeoEditionSpec $spec): GeoRelease
    {
        $resolved = $this->http->json('https://data.jsdelivr.com/v1/packages/npm/'.$spec->package.'/resolved?specifier=latest', $this->key());
        $version = is_string($resolved['version'] ?? null) ? $resolved['version'] : '';

        if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            throw GeoUpdateException::because(GeoUpdateFailure::DiscoveryFailed, $this->key());
        }

        $listing = $this->http->json('https://data.jsdelivr.com/v1/packages/npm/'.$spec->package.'@'.$version.'?structure=flat', $this->key());
        $entry = collect(is_array($listing['files'] ?? null) ? $listing['files'] : [])
            ->first(fn (mixed $file): bool => is_array($file) && ($file['name'] ?? null) === '/'.$spec->file);

        if (! is_array($entry) || ! is_string($entry['hash'] ?? null) || ! is_int($entry['size'] ?? null)) {
            throw GeoUpdateException::because(GeoUpdateFailure::DiscoveryFailed, $this->key());
        }

        return new GeoRelease(
            source: $this->key(),
            token: $version,
            url: 'https://cdn.jsdelivr.net/npm/'.$spec->package.'@'.$version.'/'.$spec->file,
            format: 'gz',
            checksumAlgorithm: 'sha256',
            checksum: $entry['hash'],
            size: $entry['size'],
        );
    }
}
