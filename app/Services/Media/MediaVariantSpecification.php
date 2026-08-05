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
    ) {}
}
