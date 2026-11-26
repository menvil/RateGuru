<?php

namespace App\Services\Media\Exceptions;

use App\Enums\MediaTool;
use RuntimeException;

/**
 * ffmpeg or ffprobe was stopped by a signal — a worker shutting down, the
 * kernel reclaiming memory — rather than finding anything wrong with the
 * file. Not a VideoIngestException: the file is not refused, and the job
 * runs again.
 */
final class MediaToolInterruptedException extends RuntimeException
{
    public static function bySignal(MediaTool $tool): self
    {
        return new self("{$tool->value} was stopped by a signal before it finished.");
    }
}
