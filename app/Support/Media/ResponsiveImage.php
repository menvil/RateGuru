<?php

namespace App\Support\Media;

/**
 * Everything a Blade template needs to render one context-appropriate
 * <img>: the chosen src, an optional srcset/sizes pair, and the real
 * width/height of whichever source src points at (never the master's,
 * unless the master is what was actually chosen).
 */
final readonly class ResponsiveImage
{
    public function __construct(
        public string $src,
        public ?string $srcset,
        public ?string $sizes,
        public int $width,
        public int $height,
    ) {}
}
