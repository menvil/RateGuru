<?php

namespace App\Support\Media;

use App\Enums\ImageOrientation;
use InvalidArgumentException;

/**
 * Classifies an image's orientation from its raw pixel dimensions.
 *
 * Framework-agnostic on purpose: it does not read files, format URLs, or
 * depend on Laravel. Ratio is always width / height.
 */
final class ImageOrientationClassifier
{
    private const PORTRAIT_MAX_RATIO = 0.85;

    private const LANDSCAPE_MIN_RATIO = 1.18;

    public function classify(int $width, int $height): ImageOrientation
    {
        if ($width <= 0 || $height <= 0) {
            throw new InvalidArgumentException('Width and height must both be positive integers.');
        }

        $ratio = $width / $height;

        if ($ratio < self::PORTRAIT_MAX_RATIO) {
            return ImageOrientation::Portrait;
        }

        if ($ratio > self::LANDSCAPE_MIN_RATIO) {
            return ImageOrientation::Landscape;
        }

        return ImageOrientation::Square;
    }
}
