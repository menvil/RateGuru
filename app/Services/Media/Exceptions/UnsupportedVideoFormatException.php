<?php

namespace App\Services\Media\Exceptions;

final class UnsupportedVideoFormatException extends VideoIngestException
{
    public static function format(string $formatName): self
    {
        return new self("The video's format ({$formatName}) is not one the pipeline accepts.");
    }
}
