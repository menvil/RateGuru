<?php

use App\Services\Media\Exceptions\VideoUnreadableException;
use App\Services\Media\VideoIngestPolicy;
use App\Services\Media\VideoProbe;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/*
 * VideoProbe and FfmpegInput against the real ffprobe, on files the real
 * ffmpeg makes. RATEGURU_REAL_FFMPEG=1 runs these: CI's PostgreSQL tests job
 * installs Ubuntu's ffmpeg and sets it (the hosts run Ubuntu's ffmpeg too);
 * anywhere else they are skipped, so a machine without ffmpeg runs the suite
 * unchanged. The last test, which needs no ffmpeg, proves CI does run them.
 */

function requireRealFfmpeg(): string
{
    if (getenv('RATEGURU_REAL_FFMPEG') !== '1') {
        test()->markTestSkipped('RATEGURU_REAL_FFMPEG=1 runs it: it needs ffmpeg and ffprobe on PATH');
    }

    $dir = sys_get_temp_dir().'/real-ffmpeg-'.bin2hex(random_bytes(6));
    mkdir($dir, 0o755);

    return $dir;
}

/** @param  list<string>  $arguments */
function realFfmpeg(array $arguments): Process
{
    $process = new Process(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', ...$arguments], null, null, null, 120);
    $process->run();

    return $process;
}

/** @param  list<string>  $arguments */
function makeWithRealFfmpeg(array $arguments): void
{
    $process = realFfmpeg($arguments);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
}

afterEach(function () {
    if (isset($this->dir)) {
        exec('rm -rf '.escapeshellarg($this->dir));
    }
});

it('probes an MP4 with sound as the clip it is, and the policy takes it', function () {
    $this->dir = requireRealFfmpeg();
    $path = $this->dir.'/clip.mp4';
    makeWithRealFfmpeg([
        '-f', 'lavfi', '-i', 'testsrc=duration=2:size=1280x720:rate=25',
        '-f', 'lavfi', '-i', 'sine=frequency=440:duration=2',
        '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', $path,
    ]);

    $probe = app(VideoProbe::class)->probe($path);

    expect($probe->formatName)->toBe('mov,mp4,m4a,3gp,3g2,mj2')
        ->and($probe->durationMs)->toBeGreaterThan(1_900)->toBeLessThan(2_200)
        ->and([$probe->width, $probe->height])->toBe([1280, 720])
        ->and($probe->frameRate)->toBe(25.0)
        ->and($probe->videoCodec)->toBe('h264')
        ->and($probe->audioCodec)->toBe('aac')
        ->and($probe->hasAudio())->toBeTrue();

    VideoIngestPolicy::fromConfig()->assertAcceptable($probe, (int) filesize($path));
});

it('probes an animated GIF and a WebM, the other formats an upload may be', function () {
    $this->dir = requireRealFfmpeg();
    makeWithRealFfmpeg(['-f', 'lavfi', '-i', 'testsrc=duration=1:size=320x240:rate=10', $this->dir.'/loop.gif']);
    makeWithRealFfmpeg(['-f', 'lavfi', '-i', 'testsrc=duration=1:size=320x240:rate=25', '-c:v', 'libvpx', '-b:v', '200k', $this->dir.'/clip.webm']);

    $gif = app(VideoProbe::class)->probe($this->dir.'/loop.gif');
    $webm = app(VideoProbe::class)->probe($this->dir.'/clip.webm');

    expect($gif->formatName)->toBe('gif')
        ->and([$gif->width, $gif->height])->toBe([320, 240])
        ->and($gif->hasAudio())->toBeFalse()
        ->and($webm->formatName)->toBe('matroska,webm')
        ->and($webm->videoCodec)->toBe('vp8');
});

it('reads a clip filmed sideways as the portrait picture it shows', function () {
    $this->dir = requireRealFfmpeg();
    makeWithRealFfmpeg(['-f', 'lavfi', '-i', 'testsrc=duration=1:size=1280x720:rate=25', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', $this->dir.'/flat.mp4']);

    // ffmpeg 6 and later take a display rotation on the input; 4.4 a rotate tag.
    $rotated = $this->dir.'/sideways.mp4';
    $modern = realFfmpeg(['-display_rotation', '90', '-i', $this->dir.'/flat.mp4', '-c', 'copy', $rotated]);

    if ($modern->getExitCode() !== 0) {
        makeWithRealFfmpeg(['-i', $this->dir.'/flat.mp4', '-metadata:s:v:0', 'rotate=90', '-c', 'copy', $rotated]);
    }

    $probe = app(VideoProbe::class)->probe($rotated);

    expect([$probe->width, $probe->height])->toBe([720, 1280])
        ->and($probe->rotation)->toBeIn([90, 270]);
});

it('refuses a playlist saved under a video name, and a file that is no video at all', function () {
    $this->dir = requireRealFfmpeg();
    file_put_contents($this->dir.'/playlist.mp4', "#EXTM3U\n#EXT-X-TARGETDURATION:1\n#EXTINF:1,\nfile:///etc/hosts\n#EXT-X-ENDLIST\n");
    file_put_contents($this->dir.'/notes.mp4', "just some text\n");

    foreach (['playlist.mp4', 'notes.mp4'] as $name) {
        expect(fn () => app(VideoProbe::class)->probe($this->dir.'/'.$name))
            ->toThrow(VideoUnreadableException::class, null, $name);
    }
});

it('runs in the PostgreSQL tests job, against Ubuntu\'s ffmpeg', function () {
    $job = Yaml::parseFile(base_path('.github/workflows/ci.yml'))['jobs']['tests'];
    $steps = collect($job['steps']);
    $names = $steps->pluck('name')->all();

    $install = $steps->firstWhere('name', 'Install ffmpeg');
    expect($install['run'] ?? '')->toContain('apt-get -o Acquire::Retries=3 install -y -qq --no-install-recommends ffmpeg');

    $run = $steps->firstWhere('name', 'Run PostgreSQL tests');
    expect($run['env']['RATEGURU_REAL_FFMPEG'] ?? null)->toBe('1')
        ->and(array_search('Install ffmpeg', $names, true))->toBeLessThan(array_search('Run PostgreSQL tests', $names, true));
});
