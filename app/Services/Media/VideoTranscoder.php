<?php

namespace App\Services\Media;

use App\Enums\MediaTool;
use App\Services\Media\Exceptions\MediaToolTimedOutException;
use App\Services\Media\Exceptions\MediaToolUnavailableException;
use App\Services\Media\Exceptions\VideoUnreadableException;

/**
 * The ffmpeg runs that turn an upload into what a page plays: an H.264/AAC
 * MP4 for every rendition, and a PNG poster frame for the image pipeline.
 * Every input goes through FfmpegInput's whitelists; nothing is read from a
 * file's own metadata into the output (-map_metadata -1: phone recordings
 * carry where they were taken), and the rotation a phone records is applied
 * to the pixels, which is ffmpeg's default when it re-encodes — so width and
 * height here are always the picture as shown.
 */
final class VideoTranscoder
{
    /** Two threads, so a transcode never takes over the host it shares with the site. */
    public const int THREADS = 2;

    /** No rendition plays faster than this; a 60 fps phone clip is halved. */
    public const int MAX_FRAME_RATE = 30;

    public function __construct(private readonly MediaToolRunner $runner) {}

    /**
     * @throws VideoUnreadableException when ffmpeg cannot transcode the file
     * @throws MediaToolUnavailableException
     * @throws MediaToolTimedOutException
     */
    public function transcode(string $input, VideoProbeResult $probe, VideoRendition $rendition, string $output, bool $withAudio): void
    {
        [$width, $height] = $rendition->dimensionsFor($probe->width, $probe->height);

        $this->run([
            ...FfmpegInput::arguments($input),
            '-map', '0:v:0',
            ...($withAudio ? ['-map', '0:a:0'] : []),
            '-vf', "scale={$width}:{$height}",
            '-fpsmax', (string) self::MAX_FRAME_RATE,
            '-c:v', 'libx264',
            '-preset', 'veryfast',
            '-profile:v', 'high',
            '-pix_fmt', 'yuv420p',
            '-crf', (string) $rendition->crf,
            '-maxrate', "{$rendition->maxrateKbps}k",
            '-bufsize', ($rendition->maxrateKbps * 2).'k',
            // A keyframe every two seconds, so a player can start anywhere.
            '-force_key_frames', 'expr:gte(t,n_forced*2)',
            ...($withAudio ? ['-c:a', 'aac', '-b:a', "{$rendition->audioKbps}k", '-ac', '2'] : ['-an']),
            '-sn',
            '-dn',
            '-map_metadata', '-1',
            '-map_chapters', '-1',
            // The index at the front, so playback starts before the file is in.
            '-movflags', '+faststart',
            // Before the input it bounds decoding; here, the encoder.
            '-threads', (string) self::THREADS,
            '-f', 'mp4',
            $output,
        ]);
    }

    /**
     * One frame as a PNG, taken a tenth of the way in (at most three seconds)
     * so it is not the black first frame most clips open on.
     *
     * @throws VideoUnreadableException
     * @throws MediaToolUnavailableException
     * @throws MediaToolTimedOutException
     */
    public function poster(string $video, ?int $durationMs, string $output): void
    {
        $at = $durationMs === null ? 0.0 : min(3.0, $durationMs / 1000 / 10);

        $this->run([
            // Before the input: a seek on the input, not a decode up to it.
            '-ss', sprintf('%.3F', $at),
            ...FfmpegInput::arguments($video),
            '-frames:v', '1',
            '-an',
            '-c:v', 'png',
            '-f', 'image2',
            $output,
        ]);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function run(array $arguments): void
    {
        $result = $this->runner->run(MediaTool::Ffmpeg, [
            '-nostdin',
            '-hide_banner',
            '-loglevel', 'error',
            '-y',
            // The scale filter takes every core unless told otherwise.
            '-filter_threads', (string) self::THREADS,
            '-threads', (string) self::THREADS,
            ...$arguments,
        ], max(1, (int) config('media.video.transcode_timeout_seconds')));

        if (! $result->succeeded()) {
            throw VideoUnreadableException::transcodeFailed();
        }
    }
}
