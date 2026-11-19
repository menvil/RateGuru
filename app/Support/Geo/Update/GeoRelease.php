<?php

namespace App\Support\Geo\Update;

/**
 * A build a source offers: a version token to compare with what is installed,
 * the exact-version address to download, what the download is (a gzip file,
 * or an npm package holding one) and, where the source publishes it, a
 * checksum and a size to hold the download to.
 */
final readonly class GeoRelease
{
    public function __construct(
        public string $source,
        public string $token,
        public string $url,
        public string $format,
        public ?string $member = null,
        public ?string $checksumAlgorithm = null,
        public ?string $checksum = null,
        public ?int $size = null,
    ) {}

    public function isPackage(): bool
    {
        return $this->format === 'tgz';
    }
}
