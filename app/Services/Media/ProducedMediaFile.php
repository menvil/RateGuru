<?php

namespace App\Services\Media;

/**
 * A file a media tool wrote to local disk — a transcoded video or one of its
 * renditions — with what its probe found, ready to be stored as a variant.
 */
final readonly class ProducedMediaFile
{
    public function __construct(
        public string $localPath,
        public string $mimeType,
        public string $extension,
        public int $byteSize,
        public int $width,
        public int $height,
        public ?int $durationMs = null,
    ) {}
}
