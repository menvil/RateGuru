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
        // Nullable because the source genuinely can be: media_assets.width and
        // .height are nullable columns, so an asset stored before dimensions were
        // recorded — or one whose probe failed — has neither. Declaring these
        // `int` did not make the data non-null; it made every caller throw a
        // TypeError on such an asset, in six construction sites across the post
        // presenter and the avatar resolver. A missing dimension is a missing
        // attribute, which every template already treats as absent
        // (`$image?->width`, `@if($width)`) rather than as an error.
        public ?int $width,
        public ?int $height,
    ) {}
}
