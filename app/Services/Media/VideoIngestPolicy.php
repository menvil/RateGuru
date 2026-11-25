<?php

namespace App\Services\Media;

use App\Services\Media\Exceptions\UnsupportedVideoFormatException;
use App\Services\Media\Exceptions\VideoDimensionsExceededException;
use App\Services\Media\Exceptions\VideoFrameRateExceededException;
use App\Services\Media\Exceptions\VideoIngestException;
use App\Services\Media\Exceptions\VideoStreamLayoutException;
use App\Services\Media\Exceptions\VideoTooLargeException;
use App\Services\Media\Exceptions\VideoTooLongException;
use App\Services\Media\Exceptions\VideoUnreadableException;

/**
 * What an uploaded or imported video or animated GIF may be, from
 * config('uploads.videos'): its size before anything reads it, then — from
 * what ffprobe found — its format, a video stream, its length, its picture
 * and its frame rate. Extra streams (a second audio track, subtitles, the
 * timecode and data tracks phones add) are allowed: transcoding keeps the
 * first video and the first audio stream only.
 */
final readonly class VideoIngestPolicy
{
    /**
     * A frame rate within this much of the limit passes: 59.94 and 60.0 are
     * both "60 frames a second".
     */
    private const float FRAME_RATE_TOLERANCE = 0.5;

    public function __construct(
        public int $maxBytes,
        public int $maxDurationMs,
        public int $maxWidth,
        public int $maxHeight,
        public int $maxFrameRate,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            maxBytes: (int) config('uploads.videos.max_kilobytes') * 1024,
            maxDurationMs: (int) config('uploads.videos.max_duration_seconds') * 1000,
            maxWidth: (int) config('uploads.videos.max_width'),
            maxHeight: (int) config('uploads.videos.max_height'),
            maxFrameRate: (int) config('uploads.videos.max_frame_rate'),
        );
    }

    /**
     * Checked before ffprobe reads the file: a file over the limit is never
     * opened by a tool at all.
     *
     * @throws VideoTooLargeException
     */
    public function assertWithinByteLimit(int $byteSize): void
    {
        if ($byteSize > $this->maxBytes) {
            throw VideoTooLargeException::exceedsMaxBytes($byteSize, $this->maxBytes);
        }
    }

    /**
     * @throws VideoIngestException
     */
    public function assertAcceptable(VideoProbeResult $probe, int $byteSize): void
    {
        $this->assertWithinByteLimit($byteSize);

        // FfmpegInput's whitelist already refuses any other demuxer; this
        // states the same rule where the decision is made.
        if (! in_array($probe->formatName, FfmpegInput::DEMUXERS, true)) {
            throw UnsupportedVideoFormatException::format($probe->formatName);
        }

        if ($probe->videoStreamCount === 0) {
            throw VideoStreamLayoutException::noVideoStream();
        }

        if ($probe->durationMs === null || $probe->durationMs <= 0) {
            throw VideoUnreadableException::noDuration();
        }

        if ($probe->durationMs > $this->maxDurationMs) {
            throw VideoTooLongException::exceedsMaxDuration($probe->durationMs, $this->maxDurationMs);
        }

        if ($probe->width > $this->maxWidth || $probe->height > $this->maxHeight) {
            throw VideoDimensionsExceededException::exceeds($probe->width, $probe->height, $this->maxWidth, $this->maxHeight);
        }

        // A GIF's frame rate is whatever delay its frames name, often 100 a
        // second for "as fast as possible"; turning it into a video sets the
        // rate, so only a real video's rate is held to the limit.
        if ($probe->formatName !== 'gif'
            && $probe->frameRate !== null
            && $probe->frameRate > $this->maxFrameRate + self::FRAME_RATE_TOLERANCE) {
            throw VideoFrameRateExceededException::exceeds($probe->frameRate, $this->maxFrameRate);
        }
    }
}
