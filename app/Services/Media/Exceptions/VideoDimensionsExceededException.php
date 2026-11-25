<?php

namespace App\Services\Media\Exceptions;

final class VideoDimensionsExceededException extends VideoIngestException
{
    public static function exceeds(int $width, int $height, int $maxWidth, int $maxHeight): self
    {
        return new self("The video is {$width}x{$height}, beyond the {$maxWidth}x{$maxHeight} limit.");
    }
}
