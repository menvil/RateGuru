<?php

namespace App\Services\Images;

/**
 * Everything needed to build a MediaAsset for a just-stored file. No
 * canonical URL: disk + path are the identity, resolved at read time.
 */
final readonly class StoredMedia
{
    public function __construct(
        public string $disk,
        public string $path,
        public ?string $originalFilename,
        public string $mimeType,
        public ?string $extension,
        public int $byteSize,
        public ?int $width,
        public ?int $height,
    ) {}
}
