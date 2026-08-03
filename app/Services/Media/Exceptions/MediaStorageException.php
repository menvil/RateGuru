<?php

namespace App\Services\Media\Exceptions;

use RuntimeException;

final class MediaStorageException extends RuntimeException
{
    public static function couldNotReadUploadedFile(string $originalFilename): self
    {
        return new self("Could not read uploaded file [{$originalFilename}].");
    }

    public static function couldNotStore(string $disk, string $path): self
    {
        return new self("Could not store media at [{$disk}:{$path}].");
    }

    public static function couldNotDelete(string $disk, string $path): self
    {
        return new self("Could not delete media at [{$disk}:{$path}].");
    }

    public static function notFound(string $disk, string $path): self
    {
        return new self("Media not found at [{$disk}:{$path}].");
    }
}
