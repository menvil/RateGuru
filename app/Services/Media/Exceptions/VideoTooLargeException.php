<?php

namespace App\Services\Media\Exceptions;

final class VideoTooLargeException extends VideoIngestException
{
    public static function exceedsMaxBytes(int $actualBytes, int $maxBytes): self
    {
        return new self("The video is {$actualBytes} bytes, exceeding the {$maxBytes} byte limit.");
    }
}
