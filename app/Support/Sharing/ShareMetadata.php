<?php

namespace App\Support\Sharing;

final readonly class ShareMetadata
{
    public function __construct(
        public string $title,
        public string $description,
        public string $url,
        public ?string $imageUrl,
        public string $siteName,
    ) {}
}
