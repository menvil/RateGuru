<?php

use App\Services\Images\ImageStorage;
use App\Services\Images\LocalImageStorage;

it('resolves image storage contract to local implementation', function () {
    $storage = app(ImageStorage::class);

    expect($storage)->toBeInstanceOf(LocalImageStorage::class);
});
