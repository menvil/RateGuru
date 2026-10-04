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
        public int $width,
        public int $height,
    ) {}
}
