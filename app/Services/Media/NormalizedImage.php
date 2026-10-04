<?php

namespace App\Services\Media;

final readonly class NormalizedImage
{
    public function __construct(
        public string $bytes,
        public string $mimeType,
        public string $extension,
        public int $byteSize,
        public int $width,
        public int $height,
    ) {}
}
