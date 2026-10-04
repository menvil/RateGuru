<?php

namespace App\Services\Media;

/**
 * Everything needed to build a MediaAsset for a just-stored file. No
 * canonical URL: disk + path are the identity, resolved at read time by
 * MediaUrlResolver.
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

    public function location(): MediaLocation
    {
        return new MediaLocation($this->disk, $this->path);
    }
}
