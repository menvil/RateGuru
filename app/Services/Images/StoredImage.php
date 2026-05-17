<?php

namespace App\Services\Images;

final readonly class StoredImage
{
    public function __construct(
        public string $path,
        public ?string $url = null,
        public ?string $thumbnailUrl = null,
        public string $disk = 'public',
    ) {}
}
