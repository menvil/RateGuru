<?php

use App\Services\Media\Exceptions\UnsupportedVideoFormatException;
use App\Services\Media\Exceptions\VideoDimensionsExceededException;
use App\Services\Media\Exceptions\VideoFrameRateExceededException;
use App\Services\Media\Exceptions\VideoStreamLayoutException;
use App\Services\Media\Exceptions\VideoTooLargeException;
use App\Services\Media\Exceptions\VideoTooLongException;
use App\Services\Media\Exceptions\VideoUnreadableException;
use App\Services\Media\VideoIngestPolicy;
use App\Services\Media\VideoProbeResult;

/** A probe of a clip every limit accepts, with the given fields changed. */
function acceptableVideoProbe(array $changes = []): VideoProbeResult
{
    return new VideoProbeResult(...$changes + [
        'formatName' => 'mov,mp4,m4a,3gp,3g2,mj2',
        'durationMs' => 15_000,
        'width' => 1080,
        'height' => 1920,
        'rotation' => 0,
        'frameRate' => 30.0,
        'videoCodec' => 'h264',
        'audioCodec' => 'aac',
        'videoStreamCount' => 1,
        'audioStreamCount' => 1,
        'otherStreamCount' => 0,
    ]);
}

function testVideoPolicy(): VideoIngestPolicy
{
    return new VideoIngestPolicy(maxBytes: 100 * 1024 * 1024, maxDurationMs: 90_000, maxWidth: 3840, maxHeight: 3840, maxFrameRate: 60);
}

it('reads its limits from the upload configuration', function () {
    config(['uploads.videos' => ['max_kilobytes' => 2048, 'max_duration_seconds' => 30, 'max_width' => 1920, 'max_height' => 1080, 'max_frame_rate' => 30]]);

    expect(VideoIngestPolicy::fromConfig())->toEqual(new VideoIngestPolicy(2048 * 1024, 30_000, 1920, 1080, 30));
});

it('ships a minute and a half, 100 MB, a 4K picture either way up and 60 frames a second', function () {
    expect(VideoIngestPolicy::fromConfig())->toEqual(new VideoIngestPolicy(100 * 1024 * 1024, 90_000, 3840, 3840, 60));
});

it('accepts a clip at every limit, and the formats an upload may be', function () {
    $policy = testVideoPolicy();

    $policy->assertAcceptable(acceptableVideoProbe(['durationMs' => 90_000, 'width' => 3840, 'height' => 2160, 'frameRate' => 59.94]), 100 * 1024 * 1024);
    $policy->assertAcceptable(acceptableVideoProbe(['formatName' => 'matroska,webm', 'videoCodec' => 'vp9', 'audioCodec' => 'opus']), 1024);
    $policy->assertAcceptable(acceptableVideoProbe(['formatName' => 'gif', 'videoCodec' => 'gif', 'audioCodec' => null, 'audioStreamCount' => 0]), 1024);
    // A second audio track, subtitles and phone data tracks are all allowed.
    $policy->assertAcceptable(acceptableVideoProbe(['audioStreamCount' => 2, 'otherStreamCount' => 3]), 1024);

    expect(true)->toBeTrue();
});

it('refuses a file over the byte limit before anything reads it', function () {
    expect(fn () => testVideoPolicy()->assertWithinByteLimit(100 * 1024 * 1024 + 1))->toThrow(VideoTooLargeException::class);
});

it('refuses each way a probed clip can exceed what an upload may be', function () {
    foreach ([
        'over the byte limit' => [[], 100 * 1024 * 1024 + 1, VideoTooLargeException::class],
        'another format' => [['formatName' => 'avi'], 1024, UnsupportedVideoFormatException::class],
        'no video stream' => [['videoStreamCount' => 0], 1024, VideoStreamLayoutException::class],
        'no length' => [['durationMs' => null], 1024, VideoUnreadableException::class],
        'zero length' => [['durationMs' => 0], 1024, VideoUnreadableException::class],
        'too long' => [['durationMs' => 90_001], 1024, VideoTooLongException::class],
        'too wide' => [['width' => 3841], 1024, VideoDimensionsExceededException::class],
        'too tall' => [['height' => 3841], 1024, VideoDimensionsExceededException::class],
        'too fast' => [['frameRate' => 120.0], 1024, VideoFrameRateExceededException::class],
    ] as $case => [$changes, $bytes, $exception]) {
        expect(fn () => testVideoPolicy()->assertAcceptable(acceptableVideoProbe($changes), $bytes))
            ->toThrow($exception, null, $case);
    }
});

it('leaves a GIF frame rate alone, since turning it into a video sets the rate', function () {
    testVideoPolicy()->assertAcceptable(acceptableVideoProbe(['formatName' => 'gif', 'frameRate' => 100.0, 'audioStreamCount' => 0]), 1024);

    expect(true)->toBeTrue();
});
