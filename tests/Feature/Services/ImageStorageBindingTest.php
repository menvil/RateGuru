<?php

use App\Services\Images\CloudinaryImageStorage;
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

it('can resolve cloudinary image storage when configured', function () {
    config(['rateguru.images.driver' => 'cloudinary']);

    app()->forgetInstance(ImageStorage::class);

    expect(app(ImageStorage::class))->toBeInstanceOf(CloudinaryImageStorage::class);

    // Reset to local so subsequent tests are not affected
    config(['rateguru.images.driver' => 'local']);
    app()->forgetInstance(ImageStorage::class);
});
