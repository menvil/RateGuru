<?php

use App\Support\Media\WorkerTemporaryDirectory;

/*
 * The directory a worker downloads or takes media apart in: its own, per
 * user, so two targets' workers on one host never share one.
 */

beforeEach(function () {
    $this->name = 'test-'.bin2hex(random_bytes(4));
});

afterEach(function () {
    $path = WorkerTemporaryDirectory::path($this->name);
    is_link($path) ? unlink($path) : exec('rm -rf '.escapeshellarg($path));
});

it('is one per name and per user, made for this worker alone', function () {
    $directory = WorkerTemporaryDirectory::prepare($this->name, 3600);

    expect($directory)->toBe(sys_get_temp_dir().'/rateguru-'.$this->name.'-'.posix_geteuid())
        ->and(is_dir($directory))->toBeTrue()
        ->and(substr(sprintf('%o', fileperms($directory)), -3))->toBe('700');
});

it('clears what a killed run left there once old enough, and keeps the rest', function () {
    $directory = WorkerTemporaryDirectory::prepare($this->name, 3600);
    mkdir($directory.'/old-run');
    file_put_contents($directory.'/old-run/frame.jpg', 'x');
    touch($directory.'/old-run', time() - 7200);
    file_put_contents($directory.'/fresh', 'x');

    WorkerTemporaryDirectory::prepare($this->name, 3600);

    expect(file_exists($directory.'/old-run'))->toBeFalse()
        ->and(file_exists($directory.'/fresh'))->toBeTrue();
});

it('refuses a link left at its name, never clearing where it points', function () {
    $elsewhere = makeScratchDir('worker-temp-target');
    file_put_contents($elsewhere.'/keep', 'x');
    touch($elsewhere.'/keep', time() - 7200);
    symlink($elsewhere, WorkerTemporaryDirectory::path($this->name));

    try {
        expect(fn () => WorkerTemporaryDirectory::prepare($this->name, 3600))->toThrow(RuntimeException::class, 'not this worker');
        expect(file_exists($elsewhere.'/keep'))->toBeTrue();
    } finally {
        removeScratchDir($elsewhere);
    }
});
