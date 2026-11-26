<?php

use App\Enums\MediaTool;
use App\Services\Media\Exceptions\MediaToolInterruptedException;
use App\Services\Media\Exceptions\MediaToolTimedOutException;
use App\Services\Media\Exceptions\MediaToolUnavailableException;
use App\Services\Media\ProcessMediaToolRunner;

/*
 * The real runner against stand-in programs: what reaches the program, what
 * comes back, and what a missing or stalled program turns into. No ffmpeg is
 * needed for any of it.
 */

beforeEach(function () {
    $this->bin = sys_get_temp_dir().'/media-tool-runner-'.bin2hex(random_bytes(6));
    mkdir($this->bin, 0o755);
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->bin));
});

function standInMediaTool(string $dir, string $name, string $body): string
{
    $path = "{$dir}/{$name}";
    file_put_contents($path, "#!/bin/bash\n{$body}\n");
    chmod($path, 0o755);

    return $path;
}

it('hands every argument to the tool verbatim, with no shell to read it', function () {
    config(['media.video.ffprobe_binary' => standInMediaTool($this->bin, 'ffprobe', 'for arg in "$@"; do printf "[%s]\n" "$arg"; done; printf "LC_ALL=%s\n" "$LC_ALL"')]);
    $marker = $this->bin.'/shell-ran';

    $result = (new ProcessMediaToolRunner)->run(MediaTool::Ffprobe, ['-i', "file:a b.mp4; touch {$marker}", '$(id)', '*'], 5);

    expect($result->succeeded())->toBeTrue()
        ->and($result->output)->toBe("[-i]\n[file:a b.mp4; touch {$marker}]\n[\$(id)]\n[*]\nLC_ALL=C\n")
        ->and(file_exists($marker))->toBeFalse();
});

it('returns a failed run with its exit code and both outputs, for the caller to judge', function () {
    config(['media.video.ffmpeg_binary' => standInMediaTool($this->bin, 'ffmpeg', 'echo out; echo err >&2; exit 3')]);

    $result = (new ProcessMediaToolRunner)->run(MediaTool::Ffmpeg, [], 5);

    expect($result->succeeded())->toBeFalse()
        ->and($result->exitCode)->toBe(3)
        ->and($result->output)->toBe("out\n")
        ->and($result->errorOutput)->toBe("err\n");
});

it('says a tool stopped by a signal was interrupted, not that the file is bad', function () {
    config(['media.video.ffprobe_binary' => standInMediaTool($this->bin, 'ffprobe', 'kill -TERM $$')]);

    expect(fn () => (new ProcessMediaToolRunner)->run(MediaTool::Ffprobe, [], 5))
        ->toThrow(MediaToolInterruptedException::class, 'ffprobe was stopped by a signal before it finished.');

    // ffmpeg catches the signal itself and exits 255, which says the same.
    config(['media.video.ffmpeg_binary' => standInMediaTool($this->bin, 'ffmpeg', 'exit 255')]);

    expect(fn () => (new ProcessMediaToolRunner)->run(MediaTool::Ffmpeg, [], 5))
        ->toThrow(MediaToolInterruptedException::class);

    // From ffprobe, 255 is only an exit code, for the caller to judge.
    config(['media.video.ffprobe_binary' => standInMediaTool($this->bin, 'ffprobe', 'exit 255')]);

    expect((new ProcessMediaToolRunner)->run(MediaTool::Ffprobe, [], 5)->exitCode)->toBe(255);
});

it('stops a tool that runs past its time', function () {
    config(['media.video.ffmpeg_binary' => standInMediaTool($this->bin, 'ffmpeg', 'sleep 10')]);

    expect(fn () => (new ProcessMediaToolRunner)->run(MediaTool::Ffmpeg, [], 1))
        ->toThrow(MediaToolTimedOutException::class, 'ffmpeg ran longer than 1 seconds and was stopped.');
});

it('says a tool that is not on the host could not be started, naming only the tool', function () {
    $notExecutable = $this->bin.'/plain-file';
    file_put_contents($notExecutable, "#!/bin/bash\nexit 0\n");

    foreach ([
        'a missing path' => $this->bin.'/no-such-ffprobe',
        'a file that is not executable' => $notExecutable,
        'a name not on PATH' => 'no-such-ffprobe-'.bin2hex(random_bytes(4)),
    ] as $case => $binary) {
        config(['media.video.ffprobe_binary' => $binary]);

        try {
            (new ProcessMediaToolRunner)->run(MediaTool::Ffprobe, ['-version'], 5);
            $this->fail("{$case}: the tool ran");
        } catch (MediaToolUnavailableException $exception) {
            expect($exception->getMessage())->toBe('ffprobe could not be started.', $case);
        }
    }
});

it('finds the tools on PATH by default', function () {
    expect(MediaTool::Ffmpeg->binary())->toBe('ffmpeg')
        ->and(MediaTool::Ffprobe->binary())->toBe('ffprobe');
});

it('finds a tool with no PATH at all, as in a PHP-FPM worker, in the search directories in order', function () {
    $first = $this->bin.'/local';
    $second = $this->bin.'/distribution';
    mkdir($first, 0o755);
    mkdir($second, 0o755);
    standInMediaTool($first, 'ffprobe', 'echo pinned');
    standInMediaTool($second, 'ffprobe', 'echo distribution');
    config(['media.video.ffprobe_binary' => 'ffprobe', 'media.video.search_directories' => [$first, $second]]);

    $path = getenv('PATH');
    putenv('PATH=');

    try {
        $result = (new ProcessMediaToolRunner)->run(MediaTool::Ffprobe, [], 5);
    } finally {
        putenv('PATH='.$path);
    }

    expect($result->output)->toBe("pinned\n");
});

it('looks in /usr/local/bin, where the pinned build lives, before /usr/bin', function () {
    expect(config('media.video.search_directories'))->toBe(['/usr/local/bin', '/usr/bin']);
});
