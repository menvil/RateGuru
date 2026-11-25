<?php

use App\Services\Media\FilesystemMediaStorage;
use App\Services\Media\FilesystemMediaUrlResolver;
use App\Services\Media\MediaStorage;
use App\Services\Media\MediaToolRunner;
use App\Services\Media\MediaUrlResolver;
use App\Services\Media\ProcessMediaToolRunner;

it('binds the media storage and url resolver contracts to one shared filesystem implementation each', function () {
    expect(app(MediaStorage::class))->toBeInstanceOf(FilesystemMediaStorage::class)
        ->toBe(app(MediaStorage::class))
        ->and(app(MediaUrlResolver::class))->toBeInstanceOf(FilesystemMediaUrlResolver::class)
        ->toBe(app(MediaUrlResolver::class));
});

it('runs ffmpeg and ffprobe through the one process runner', function () {
    expect(app(MediaToolRunner::class))->toBeInstanceOf(ProcessMediaToolRunner::class)
        ->toBe(app(MediaToolRunner::class));
});
