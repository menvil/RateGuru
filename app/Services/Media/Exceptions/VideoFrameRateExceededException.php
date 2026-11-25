<?php

namespace App\Services\Media\Exceptions;

final class VideoFrameRateExceededException extends VideoIngestException
{
    public static function exceeds(float $frameRate, int $maxFrameRate): self
    {
        return new self("The video runs at {$frameRate} frames a second, beyond the {$maxFrameRate} limit.");
    }
}
