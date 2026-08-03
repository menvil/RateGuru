<?php

use App\Models\User;
use App\Services\Images\ImageStorage;
use App\Services\Images\StoredMedia;
use Illuminate\Http\UploadedFile;

it('has image storage interface contract', function () {
    expect(interface_exists(ImageStorage::class))->toBeTrue();
});

it('has stored media dto', function () {
    expect(class_exists(StoredMedia::class))->toBeTrue();
});

it('stored media dto has the required properties and no canonical url', function () {
    $stored = new StoredMedia(
        disk: 'public',
        path: 'posts/1/image.jpg',
        originalFilename: 'image.jpg',
        mimeType: 'image/jpeg',
        extension: 'jpg',
        byteSize: 12_345,
        width: 1600,
        height: 900,
    );

    expect($stored->disk)->toBe('public')
        ->and($stored->path)->toBe('posts/1/image.jpg')
        ->and($stored->originalFilename)->toBe('image.jpg')
        ->and($stored->mimeType)->toBe('image/jpeg')
        ->and($stored->extension)->toBe('jpg')
        ->and($stored->byteSize)->toBe(12_345)
        ->and($stored->width)->toBe(1600)
        ->and($stored->height)->toBe(900);

    expect(property_exists($stored, 'url'))->toBeFalse()
        ->and(property_exists($stored, 'thumbnailUrl'))->toBeFalse();
});

it('stored media dto allows nullable original filename and dimensions', function () {
    $stored = new StoredMedia(
        disk: 'public',
        path: 'posts/1/image.jpg',
        originalFilename: null,
        mimeType: 'image/jpeg',
        extension: null,
        byteSize: 0,
        width: null,
        height: null,
    );

    expect($stored->originalFilename)->toBeNull()
        ->and($stored->extension)->toBeNull()
        ->and($stored->width)->toBeNull()
        ->and($stored->height)->toBeNull();
});

it('image storage interface has store post image and store avatar methods', function () {
    $reflection = new ReflectionClass(ImageStorage::class);

    expect($reflection->isInterface())->toBeTrue()
        ->and($reflection->hasMethod('storePostImage'))->toBeTrue()
        ->and($reflection->hasMethod('storeAvatar'))->toBeTrue();

    foreach (['storePostImage', 'storeAvatar'] as $methodName) {
        $method = $reflection->getMethod($methodName);
        $params = $method->getParameters();

        expect($method->getNumberOfParameters())->toBe(2)
            ->and($params[0]->getType()->getName())->toBe(UploadedFile::class)
            ->and($params[1]->getType()->getName())->toBe(User::class)
            ->and($method->getReturnType()->getName())->toBe(StoredMedia::class);
    }
});
