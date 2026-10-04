<?php

namespace App\Services\Media\Exceptions;

use RuntimeException;
use Throwable;

final class MediaStorageException extends RuntimeException
{
    public static function couldNotReadUploadedFile(string $originalFilename, ?Throwable $previous = null): self
    {
        return new self("Could not read uploaded file [{$originalFilename}].", previous: $previous);
    }

    public static function couldNotStore(string $disk, string $path, ?Throwable $previous = null): self
    {
        return new self("Could not store media at [{$disk}:{$path}].", previous: $previous);
    }

    public static function couldNotDelete(string $disk, string $path, ?Throwable $previous = null): self
    {
        return new self("Could not delete media at [{$disk}:{$path}].", previous: $previous);
    }

    public static function notFound(string $disk, string $path, ?Throwable $previous = null): self
    {
        return new self("Media not found at [{$disk}:{$path}].", previous: $previous);
    }

    public static function couldNotReadStream(string $disk, string $path): self
    {
        return new self("Could not read media stream at [{$disk}:{$path}].");
    }
}
