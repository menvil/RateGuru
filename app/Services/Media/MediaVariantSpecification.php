<?php

namespace App\Services\Media;

use App\Enums\MediaResizeMode;
use App\Enums\MediaVariantName;

/**
 * What one variant of an asset should look like. Dimensions/mode/quality
 * are architectural constants (see MediaVariantSpecificationRegistry), not
 * environment-tunable config.
 */
final readonly class MediaVariantSpecification
{
    public function __construct(
        public MediaVariantName $name,
        public int $maxWidth,
        public int $maxHeight,
        public MediaResizeMode $mode,
        public int $quality,
        public ?string $outputMimeType = null,
    ) {}

    /**
     * Whether generating this variant for a source image of the given
     * dimensions would require upscaling (Contain never upscales; the two
     * cover modes crop-to-fill and so upscale whenever either source
     * dimension is smaller than the corresponding target).
     */
    public function wouldUpscale(int $sourceWidth, int $sourceHeight): bool
    {
        return match ($this->mode) {
            MediaResizeMode::Contain => false,
            MediaResizeMode::CoverSquare, MediaResizeMode::Cover => $sourceWidth < $this->maxWidth || $sourceHeight < $this->maxHeight,
        };
    }
}
