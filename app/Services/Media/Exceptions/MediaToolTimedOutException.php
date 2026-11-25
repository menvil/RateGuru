<?php

namespace App\Services\Media\Exceptions;

use App\Enums\MediaTool;
use RuntimeException;
use Throwable;

/** ffmpeg or ffprobe ran past the time its caller allowed it and was stopped. */
final class MediaToolTimedOutException extends RuntimeException
{
    public static function after(MediaTool $tool, int $timeoutSeconds, ?Throwable $previous = null): self
    {
        return new self("{$tool->value} ran longer than {$timeoutSeconds} seconds and was stopped.", 0, $previous);
    }
}
