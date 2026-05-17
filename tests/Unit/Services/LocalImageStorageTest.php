<?php

use App\Services\Images\ImageStorage;
use App\Services\Images\LocalImageStorage;

it('local image storage implements image storage interface', function () {
    expect(new LocalImageStorage())->toBeInstanceOf(ImageStorage::class);
});

it('local image storage has store post image method', function () {
    $reflection = new ReflectionClass(LocalImageStorage::class);

    expect($reflection->hasMethod('storePostImage'))->toBeTrue();
});
