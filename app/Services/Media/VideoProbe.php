<?php

namespace App\Services\Media;

use App\Enums\MediaTool;
use App\Services\Media\Exceptions\MediaToolTimedOutException;
use App\Services\Media\Exceptions\MediaToolUnavailableException;
use App\Services\Media\Exceptions\VideoUnreadableException;

/**
 * Reads what a video or animated GIF holds with ffprobe, through FfmpegInput's
 * whitelists. A file ffprobe cannot read — not a media file, a format off the
 * whitelist, a truncated upload — is VideoUnreadableException: the reason is
 * in ffprobe's output, which is never put in a message a user could see.
 */
final class VideoProbe
{
    public function __construct(private readonly MediaToolRunner $runner) {}

    /**
     * @throws VideoUnreadableException
     * @throws MediaToolUnavailableException
     * @throws MediaToolTimedOutException
     */
    public function probe(string $path): VideoProbeResult
    {
        $result = $this->runner->run(MediaTool::Ffprobe, [
            '-v', 'error',
            '-show_format',
            '-show_streams',
            '-of', 'json',
            ...FfmpegInput::arguments($path),
        ], max(1, (int) config('media.video.probe_timeout_seconds')));

        if (! $result->succeeded()) {
            throw VideoUnreadableException::probeFailed();
        }

        $data = json_decode($result->output, true);

        if (! is_array($data) || ! is_array($data['format'] ?? null)) {
            throw VideoUnreadableException::probeFailed();
        }

        return VideoProbeResult::fromFfprobe($data);
    }
}
