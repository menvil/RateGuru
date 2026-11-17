<?php

namespace App\Services\Geo\Sources;

use App\Enums\GeoUpdateFailure;
use App\Exceptions\Geo\GeoUpdateException;
use App\Services\Geo\GeoHttp;
use App\Support\Geo\Update\GeoEditionSpec;
use App\Support\Geo\Update\GeoRelease;

/**
 * The npm registry: the package's latest version and the SHA-512 the registry
 * publishes for its tarball, which holds the gzipped database as
 * package/<file>.
 */
final readonly class NpmTarballSource implements GeoDatabaseSource
{
    public function __construct(private GeoHttp $http) {}

    public function key(): string
    {
        return 'npm';
    }

    public function discover(GeoEditionSpec $spec): GeoRelease
    {
        $latest = $this->http->json('https://registry.npmjs.org/'.$spec->package.'/latest', $this->key());
        $version = is_string($latest['version'] ?? null) ? $latest['version'] : '';
        $integrity = is_string($latest['dist']['integrity'] ?? null) ? $latest['dist']['integrity'] : '';

        if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1 || ! str_starts_with($integrity, 'sha512-')) {
            throw GeoUpdateException::because(GeoUpdateFailure::DiscoveryFailed, $this->key());
        }

        return new GeoRelease(
            source: $this->key(),
            token: $version,
            url: 'https://registry.npmjs.org/'.$spec->package.'/-/'.$spec->package.'-'.$version.'.tgz',
            format: 'tgz',
            member: 'package/'.$spec->file,
            checksumAlgorithm: 'sha512',
            checksum: substr($integrity, strlen('sha512-')),
        );
    }
}
