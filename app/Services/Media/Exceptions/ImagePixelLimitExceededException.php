<?php

namespace App\Services\Media\Exceptions;

final class ImagePixelLimitExceededException extends ImageIngestException
{
    public static function exceedsMaxPixels(int $pixels, int $maxPixels): self
    {
        return new self("The image has {$pixels} total pixels, exceeding the maximum of {$maxPixels}.");
    }
}
