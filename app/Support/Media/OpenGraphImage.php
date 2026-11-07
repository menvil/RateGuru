<?php

namespace App\Support\Media;

/**
 * A single, fixed image for Open Graph / Twitter card metadata — deliberately
 * not a ResponsiveImage: OG needs exactly one URL/size/type, never a srcset.
 */
final readonly class OpenGraphImage
{
    public function __construct(
        public string $url,
        public string $mimeType,
        // Unknown for an image stored before its size was recorded: the
        // page leaves og:image:width/height out rather than failing.
        public ?int $width,
        public ?int $height,
    ) {}
}
