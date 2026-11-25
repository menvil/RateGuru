<?php

use App\Enums\MediaTool;
use App\Enums\MediaVariantName;
use App\Services\Media\Exceptions\VideoUnreadableException;
use App\Services\Media\FfmpegInput;
use App\Services\Media\MediaToolResult;
use App\Services\Media\VideoProbeResult;
use App\Services\Media\VideoRendition;
use App\Services\Media\VideoTranscoder;

/** A probe of a clip, with only the picture's size mattering to the transcoder. */
function transcoderProbe(int $width, int $height): VideoProbeResult
{
    return acceptableVideoProbe(['width' => $width, 'height' => $height]);
}

it('encodes the master as H.264/AAC MP4 at the picture as shown, through the input whitelists, with nothing of the source metadata', function () {
    $runner = bindRecordingMediaToolRunner();
    config(['media.video.transcode_timeout_seconds' => 321]);

    app(VideoTranscoder::class)->transcode('/work/original', transcoderProbe(1920, 1080), VideoRendition::master(), '/work/master.mp4', true);

    expect($runner->calls)->toHaveCount(1)
        ->and($runner->calls[0]['tool'])->toBe(MediaTool::Ffmpeg)
        ->and($runner->calls[0]['timeout'])->toBe(321)
        ->and($runner->calls[0]['arguments'])->toBe([
            '-nostdin', '-hide_banner', '-loglevel', 'error', '-y', '-filter_threads', '2', '-threads', '2', '-timelimit', '642',
            ...FfmpegInput::arguments('/work/original'),
            '-map', '0:v:0',
            '-map', '0:a:0',
            '-vf', 'scale=1920:1080',
            '-fpsmax', '30',
            '-c:v', 'libx264', '-preset', 'veryfast', '-profile:v', 'high', '-pix_fmt', 'yuv420p',
            '-crf', '23', '-maxrate', '5000k', '-bufsize', '10000k',
            '-force_key_frames', 'expr:gte(t,n_forced*2)',
            '-c:a', 'aac', '-b:a', '128k', '-ac', '2',
            '-sn', '-dn', '-map_metadata', '-1', '-map_chapters', '-1',
            '-movflags', '+faststart',
            '-threads', '2',
            '-f', 'mp4', '/work/master.mp4',
        ]);
});

it('scales to the rendition\'s short side, never up, to even sides, and drops the sound when asked', function () {
    foreach ([
        'a 4K portrait clip, master' => [[2160, 3840], VideoRendition::master(), 'scale=1080:1920'],
        'a 1080p landscape clip, 720' => [[1920, 1080], VideoRendition::displays()[0], 'scale=1280:720'],
        'a 1080p landscape clip, 480' => [[1920, 1080], VideoRendition::displays()[1], 'scale=852:480'],
        'a small clip of odd size, master' => [[641, 361], VideoRendition::master(), 'scale=640:360'],
    ] as $case => [[$width, $height], $rendition, $scale]) {
        $runner = bindRecordingMediaToolRunner();

        app(VideoTranscoder::class)->transcode('/work/in', transcoderProbe($width, $height), $rendition, '/work/out.mp4', false);

        $arguments = $runner->calls[0]['arguments'];
        expect($arguments[array_search('-vf', $arguments, true) + 1])->toBe($scale, $case)
            ->and($arguments)->toContain('-an')->not->toContain('-c:a', $case)
            ->and(array_count_values($arguments)['-map'] ?? 0)->toBe(1, $case);
    }

    expect(VideoRendition::displays()[0]->name)->toBe(MediaVariantName::Video720)
        ->and(VideoRendition::displays()[1]->name)->toBe(MediaVariantName::Video480);
});

it('takes the poster a tenth of the way in, at most three seconds, seeking on the input', function () {
    foreach ([[20_000, '2.000'], [60_000, '3.000'], [800, '0.080'], [null, '0.000']] as [$durationMs, $at]) {
        $runner = bindRecordingMediaToolRunner();

        app(VideoTranscoder::class)->poster('/work/master.mp4', $durationMs, '/work/poster.png');

        expect($runner->calls[0]['arguments'])->toBe([
            '-nostdin', '-hide_banner', '-loglevel', 'error', '-y', '-filter_threads', '2', '-threads', '2', '-timelimit', '1200',
            '-ss', $at,
            ...FfmpegInput::arguments('/work/master.mp4'),
            '-frames:v', '1', '-an', '-c:v', 'png', '-f', 'image2', '/work/poster.png',
        ]);
    }
});

it('refuses a file ffmpeg cannot transcode, saying nothing of what ffmpeg said', function () {
    bindRecordingMediaToolRunner([new MediaToolResult(1, '', 'file:/work/in: Invalid data found when processing input')]);

    try {
        app(VideoTranscoder::class)->transcode('/work/in', transcoderProbe(640, 360), VideoRendition::master(), '/work/out.mp4', true);
        $this->fail('a failed transcode passed');
    } catch (VideoUnreadableException $exception) {
        expect($exception->getMessage())->toBe('The video could not be transcoded.');
    }
});
