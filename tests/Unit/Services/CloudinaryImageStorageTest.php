<?php

use App\Services\Images\CloudinaryImageStorage;
use App\Services\Images\ImageStorage;

it('cloudinary image storage implements image storage interface', function () {
    expect(new CloudinaryImageStorage())->toBeInstanceOf(ImageStorage::class);
});

it('cloudinary image storage has store post image method', function () {
    $reflection = new ReflectionClass(CloudinaryImageStorage::class);

    expect($reflection->hasMethod('storePostImage'))->toBeTrue();
});

it('cloudinary image storage throws runtime exception when store post image is called', function () {
    $user = Mockery::mock(App\Models\User::class);
    $file = Mockery::mock(Illuminate\Http\UploadedFile::class);

    expect(fn () => (new CloudinaryImageStorage())->storePostImage($file, $user))
        ->toThrow(RuntimeException::class);
});
