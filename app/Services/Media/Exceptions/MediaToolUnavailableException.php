<?php

namespace App\Services\Media\Exceptions;

use App\Enums\MediaTool;
use RuntimeException;
use Throwable;

/** ffmpeg or ffprobe could not be started — missing from the host, or not executable. */
final class MediaToolUnavailableException extends RuntimeException
{
    public static function cannotStart(MediaTool $tool, ?Throwable $previous = null): self
    {
        return new self("{$tool->value} could not be started.", 0, $previous);
    }
}
