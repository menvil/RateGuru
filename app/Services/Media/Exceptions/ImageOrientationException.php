<?php

namespace App\Services\Media\Exceptions;

use Throwable;

final class ImageOrientationException extends ImageIngestException
{
    public static function unrecognizedOrientation(int $orientation): self
    {
        return new self("Unrecognized EXIF orientation value [{$orientation}].");
    }

    public static function couldNotApplyOrientation(?Throwable $previous = null): self
    {
        return new self('Could not apply the EXIF orientation correction.', previous: $previous);
    }
}
