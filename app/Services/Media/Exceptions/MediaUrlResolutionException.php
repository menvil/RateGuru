<?php

namespace App\Services\Media\Exceptions;

use RuntimeException;
use Throwable;

final class MediaUrlResolutionException extends RuntimeException
{
    public static function forDisk(string $disk, string $path, ?Throwable $previous = null): self
    {
        return new self("Could not resolve a URL for media at [{$disk}:{$path}].", previous: $previous);
    }
}
