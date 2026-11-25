<?php

namespace App\Services\Media;

use App\Enums\MediaTool;
use App\Services\Media\Exceptions\MediaToolTimedOutException;
use App\Services\Media\Exceptions\MediaToolUnavailableException;

/**
 * Runs ffmpeg or ffprobe with an argument list — never a shell line, so no
 * argument, however it reads, is interpreted by anything but the tool. A
 * non-zero exit is the caller's to judge and comes back in the result; only a
 * tool that cannot start, or runs past its time, throws.
 */
interface MediaToolRunner
{
    /**
     * @param  list<string>  $arguments
     *
     * @throws MediaToolUnavailableException
     * @throws MediaToolTimedOutException
     */
    public function run(MediaTool $tool, array $arguments, int $timeoutSeconds): MediaToolResult;
}
