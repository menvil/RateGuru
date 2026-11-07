<?php

namespace App\Services\Media\Exceptions;

use Throwable;

final class ImageDecodeException extends ImageIngestException
{
    /**
     * Static text, and no previous exception: callers report this, and both
     * the client's file name and the temporary file's path — which can carry
     * that name encoded — are the uploader's, not a diagnosis.
     */
    public static function couldNotReadUploadedFile(): self
    {
        return new self('Could not read the uploaded file.');
    }

    public static function couldNotDecode(?Throwable $previous = null): self
    {
        return new self('The image could not be decoded — it may be corrupt or truncated.', previous: $previous);
    }
}
