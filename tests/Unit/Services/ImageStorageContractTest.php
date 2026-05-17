<?php

use App\Services\Images\ImageStorage;
use App\Services\Images\StoredImage;

it('has image storage interface contract', function () {
    expect(interface_exists(ImageStorage::class))->toBeTrue();
});

it('has stored image dto', function () {
    expect(class_exists(StoredImage::class))->toBeTrue();
});

it('stored image dto has required properties', function () {
    $stored = new StoredImage(
        path: 'posts/1/image.jpg',
        url: 'http://example.com/image.jpg',
        thumbnailUrl: null,
        disk: 'public',
    );

    expect($stored->path)->toBe('posts/1/image.jpg')
        ->and($stored->url)->toBe('http://example.com/image.jpg')
        ->and($stored->thumbnailUrl)->toBeNull()
        ->and($stored->disk)->toBe('public');
});

it('stored image dto has nullable url and thumbnail url', function () {
    $stored = new StoredImage(path: 'posts/1/image.jpg');

    expect($stored->url)->toBeNull()
        ->and($stored->thumbnailUrl)->toBeNull()
        ->and($stored->disk)->toBe('public');
});

it('image storage interface has store post image method', function () {
    $reflection = new ReflectionClass(ImageStorage::class);

    expect($reflection->isInterface())->toBeTrue()
        ->and($reflection->hasMethod('storePostImage'))->toBeTrue();

    $method = $reflection->getMethod('storePostImage');
    expect($method->getNumberOfParameters())->toBe(2);
});
