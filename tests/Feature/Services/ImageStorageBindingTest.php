<?php

use App\Services\Images\ImageStorage;
use App\Services\Images\LocalImageStorage;

it('resolves image storage contract to local implementation', function () {
    $storage = app(ImageStorage::class);

    expect($storage)->toBeInstanceOf(LocalImageStorage::class);
});

it('uses local image storage by default', function () {
    config(['rateguru.images.driver' => 'local']);

    app()->forgetInstance(ImageStorage::class);

    expect(app(ImageStorage::class))->toBeInstanceOf(LocalImageStorage::class);
});

it('fails fast for the cloudinary driver instead of resolving a non-functional placeholder', function () {
    config(['rateguru.images.driver' => 'cloudinary']);

    app()->forgetInstance(ImageStorage::class);

    expect(fn () => app(ImageStorage::class))
        ->toThrow(InvalidArgumentException::class, 'Unsupported image driver: [cloudinary].');

    config(['rateguru.images.driver' => 'local']);
    app()->forgetInstance(ImageStorage::class);
});

it('fails fast for any other unknown driver', function () {
    config(['rateguru.images.driver' => 'imgix']);

    app()->forgetInstance(ImageStorage::class);

    expect(fn () => app(ImageStorage::class))
        ->toThrow(InvalidArgumentException::class, 'Unsupported image driver: [imgix].');

    config(['rateguru.images.driver' => 'local']);
    app()->forgetInstance(ImageStorage::class);
});
