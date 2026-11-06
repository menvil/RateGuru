<?php

namespace App\Support\Sharing;

final readonly class ShareMetadata
{
    public function __construct(
        public string $title,
        public string $shareText,
        public string $description,
        public string $url,
        public ?string $imageUrl,
        public string $siteName,
        /** The post's own picture, for a share that carries the file itself; null when it has none. */
        public ?string $pictureUrl = null,
    ) {}
}
