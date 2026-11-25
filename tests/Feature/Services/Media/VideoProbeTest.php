<?php

use App\Enums\MediaTool;
use App\Services\Media\Exceptions\VideoUnreadableException;
use App\Services\Media\FfmpegInput;
use App\Services\Media\MediaToolResult;
use App\Services\Media\VideoProbe;

/**
 * ffprobe's JSON for one file, shaped the way the real tool prints it.
 *
 * @param  list<array<string, mixed>>  $streams
 * @param  array<string, mixed>  $format
 */
function ffprobeJson(array $streams, array $format = []): string
{
    return (string) json_encode([
        'streams' => $streams,
        'format' => $format + ['format_name' => 'mov,mp4,m4a,3gp,3g2,mj2', 'duration' => '14.960000'],
    ]);
}

it('asks ffprobe for the format and streams as JSON, reading the file only through the input whitelists', function () {
    $runner = bindRecordingMediaToolRunner([new MediaToolResult(0, ffprobeJson([['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1280, 'height' => 720]]), '')]);
    config(['media.video.probe_timeout_seconds' => 12]);

    app(VideoProbe::class)->probe('/tmp/upload.mp4');

    expect($runner->calls)->toHaveCount(1)
        ->and($runner->calls[0]['tool'])->toBe(MediaTool::Ffprobe)
        ->and($runner->calls[0]['timeout'])->toBe(12)
        ->and($runner->calls[0]['arguments'])->toBe([
            '-v', 'error',
            '-show_format',
            '-show_streams',
            '-of', 'json',
            ...FfmpegInput::arguments('/tmp/upload.mp4'),
        ]);
});

it('reads the picture, length, rate, codecs and stream counts of a phone clip', function () {
    bindRecordingMediaToolRunner([new MediaToolResult(0, ffprobeJson([
        ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1920, 'height' => 1080, 'avg_frame_rate' => '30000/1001'],
        ['codec_type' => 'audio', 'codec_name' => 'aac'],
        ['codec_type' => 'data', 'codec_name' => 'none'],
    ]), '')]);

    $probe = app(VideoProbe::class)->probe('/tmp/clip.mov');

    expect($probe->formatName)->toBe('mov,mp4,m4a,3gp,3g2,mj2')
        ->and($probe->durationMs)->toBe(14_960)
        ->and($probe->width)->toBe(1920)
        ->and($probe->height)->toBe(1080)
        ->and($probe->rotation)->toBe(0)
        ->and($probe->frameRate)->toBe(29.97)
        ->and($probe->videoCodec)->toBe('h264')
        ->and($probe->audioCodec)->toBe('aac')
        ->and($probe->hasAudio())->toBeTrue()
        ->and($probe->videoStreamCount)->toBe(1)
        ->and($probe->audioStreamCount)->toBe(1)
        ->and($probe->otherStreamCount)->toBe(1);
});

it('reads a clip filmed sideways as the picture it shows, from either way ffmpeg reports the turn', function () {
    foreach ([
        // ffmpeg 4.4, which the hosts run: a rotate tag, clockwise degrees.
        'rotate tag' => [['tags' => ['rotate' => '90']], 90],
        // ffmpeg 5 and later: a display matrix, counter-clockwise degrees.
        'display matrix' => [['side_data_list' => [['side_data_type' => 'Display Matrix', 'rotation' => -90]]], 90],
        'display matrix, other way' => [['side_data_list' => [['side_data_type' => 'Display Matrix', 'rotation' => 90]]], 270],
        'upside down' => [['tags' => ['rotate' => '180']], 180],
    ] as $case => [$rotation, $expected]) {
        bindRecordingMediaToolRunner([new MediaToolResult(0, ffprobeJson([
            ['codec_type' => 'video', 'codec_name' => 'hevc', 'width' => 1920, 'height' => 1080] + $rotation,
        ]), '')]);

        $probe = app(VideoProbe::class)->probe('/tmp/clip.mov');
        $sideways = $expected !== 180;

        expect($probe->rotation)->toBe($expected, $case)
            ->and([$probe->width, $probe->height])->toBe($sideways ? [1080, 1920] : [1920, 1080], $case);
    }
});

it('takes a cover picture embedded in an MP4 for another stream, never for the video', function () {
    bindRecordingMediaToolRunner([new MediaToolResult(0, ffprobeJson([
        ['codec_type' => 'video', 'codec_name' => 'mjpeg', 'width' => 600, 'height' => 600, 'disposition' => ['attached_pic' => 1]],
        ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 720, 'height' => 1280],
    ]), '')]);

    $probe = app(VideoProbe::class)->probe('/tmp/clip.mp4');

    expect($probe->videoCodec)->toBe('h264')
        ->and([$probe->width, $probe->height])->toBe([720, 1280])
        ->and($probe->videoStreamCount)->toBe(1)
        ->and($probe->otherStreamCount)->toBe(1)
        ->and($probe->hasAudio())->toBeFalse();
});

it('reads an animated GIF, and takes the length from the stream when the format gives none', function () {
    bindRecordingMediaToolRunner([new MediaToolResult(0, (string) json_encode([
        'streams' => [['codec_type' => 'video', 'codec_name' => 'gif', 'width' => 480, 'height' => 270, 'avg_frame_rate' => '0/0', 'r_frame_rate' => '100/1', 'duration' => '2.400000']],
        'format' => ['format_name' => 'gif'],
    ]), '')]);

    $probe = app(VideoProbe::class)->probe('/tmp/loop.gif');

    expect($probe->formatName)->toBe('gif')
        ->and($probe->durationMs)->toBe(2_400)
        ->and($probe->frameRate)->toBe(100.0)
        ->and($probe->hasAudio())->toBeFalse();
});

it('refuses a file ffprobe cannot read, or answers with no format, without passing on what ffprobe said', function () {
    foreach ([
        'non-zero exit' => new MediaToolResult(1, '', 'file:/tmp/x.mp4: Invalid data found when processing input'),
        'not JSON' => new MediaToolResult(0, 'garbage', ''),
        'no format' => new MediaToolResult(0, (string) json_encode(['streams' => []]), ''),
    ] as $case => $result) {
        bindRecordingMediaToolRunner([$result]);

        try {
            app(VideoProbe::class)->probe('/tmp/x.mp4');
            $this->fail("{$case}: the probe accepted it");
        } catch (VideoUnreadableException $exception) {
            expect($exception->getMessage())->not->toContain('/tmp/')->not->toContain('Invalid data', $case);
        }
    }
});
