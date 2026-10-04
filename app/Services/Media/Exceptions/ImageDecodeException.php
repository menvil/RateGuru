<?php

namespace App\Services\Media\Exceptions;

use Throwable;

final class ImageDecodeException extends ImageIngestException
{
    public static function couldNotReadUploadedFile(string $originalFilename, ?Throwable $previous = null): self
    {
        return new self("Could not read uploaded file [{$originalFilename}].", previous: $previous);
    }

    public static function couldNotDecode(?Throwable $previous = null): self
    {
        return new self('The image could not be decoded — it may be corrupt or truncated.', previous: $previous);
    }
}
