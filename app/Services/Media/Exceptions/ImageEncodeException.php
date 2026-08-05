<?php

namespace App\Services\Media\Exceptions;

use Throwable;

final class ImageEncodeException extends ImageIngestException
{
    public static function couldNotEncode(string $mimeType, ?Throwable $previous = null): self
    {
        return new self("Could not encode the normalized image as [{$mimeType}].", previous: $previous);
    }

    public static function unexpectedOutputFormat(string $expected, string $actual): self
    {
        return new self("Expected the normalized image to be [{$expected}], got [{$actual}].");
    }

    public static function exceedsMaxBytesAfterEncoding(int $actualBytes, int $maxBytes): self
    {
        return new self("The normalized image is {$actualBytes} bytes, exceeding the {$maxBytes} byte limit.");
    }
}
