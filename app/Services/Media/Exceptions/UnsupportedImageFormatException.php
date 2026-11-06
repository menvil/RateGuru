<?php

namespace App\Services\Media\Exceptions;

final class UnsupportedImageFormatException extends ImageIngestException
{
    public static function unrecognizedFormat(): self
    {
        return new self('The uploaded file is not a supported image format.');
    }

    public static function disallowedMimeType(string $mimeType): self
    {
        return new self("Images of type [{$mimeType}] are not supported.");
    }

    public static function mimeTypeMismatch(): self
    {
        return new self("The uploaded file's content does not match its declared image format.");
    }
}
