<?php

namespace App\Services\Media\Exceptions;

final class ImageDimensionsExceededException extends ImageIngestException
{
    public static function exceedsMaxDimensions(int $width, int $height, int $maxWidth, int $maxHeight): self
    {
        return new self("The image is {$width}x{$height}, exceeding the maximum of {$maxWidth}x{$maxHeight}.");
    }
}
