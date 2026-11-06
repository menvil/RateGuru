<?php

namespace App\Services\Media\Exceptions;

final class ImageTooLargeException extends ImageIngestException
{
    public static function exceedsMaxBytes(int $actualBytes, int $maxBytes): self
    {
        return new self("The image is {$actualBytes} bytes, exceeding the {$maxBytes} byte limit.");
    }
}
