<?php

namespace App\Services\Media\Exceptions;

use App\Services\Media\MediaLocation;
use RuntimeException;

final class MediaIsNotPublicException extends RuntimeException
{
    public static function forLocation(MediaLocation $location): self
    {
        return new self("Cannot resolve a public URL for private media at [{$location->disk}:{$location->path}].");
    }
}
