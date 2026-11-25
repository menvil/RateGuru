<?php

namespace App\Services\Media\Exceptions;

final class VideoTooLongException extends VideoIngestException
{
    public static function exceedsMaxDuration(int $actualMs, int $maxMs): self
    {
        return new self("The video plays for {$actualMs} ms, exceeding the {$maxMs} ms limit.");
    }
}
