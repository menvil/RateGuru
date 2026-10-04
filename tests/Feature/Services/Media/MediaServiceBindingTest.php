<?php

use App\Services\Media\FilesystemMediaStorage;
use App\Services\Media\FilesystemMediaUrlResolver;
use App\Services\Media\MediaStorage;
use App\Services\Media\MediaUrlResolver;

it('resolves the media storage contract to the filesystem implementation', function () {
    expect(app(MediaStorage::class))->toBeInstanceOf(FilesystemMediaStorage::class);
});

it('resolves the media url resolver contract to the filesystem implementation', function () {
    expect(app(MediaUrlResolver::class))->toBeInstanceOf(FilesystemMediaUrlResolver::class);
});

it('resolves media storage as a singleton', function () {
    expect(app(MediaStorage::class))->toBe(app(MediaStorage::class));
});

it('resolves the media url resolver as a singleton', function () {
    expect(app(MediaUrlResolver::class))->toBe(app(MediaUrlResolver::class));
});
