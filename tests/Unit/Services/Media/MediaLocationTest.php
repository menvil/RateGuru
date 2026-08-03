<?php

use App\Services\Media\MediaLocation;

it('accepts a valid disk and path', function () {
    $location = new MediaLocation('public', 'media/post-images/2026/08/file.jpg');

    expect($location->disk)->toBe('public')
        ->and($location->path)->toBe('media/post-images/2026/08/file.jpg');
});

it('rejects an empty disk', function () {
    expect(fn () => new MediaLocation('', 'media/post-images/file.jpg'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a blank disk', function () {
    expect(fn () => new MediaLocation('   ', 'media/post-images/file.jpg'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects an empty path', function () {
    expect(fn () => new MediaLocation('public', ''))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a blank path', function () {
    expect(fn () => new MediaLocation('public', '   '))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a full https URL as a path', function () {
    expect(fn () => new MediaLocation('public', 'https://cdn.example.test/file.jpg'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a full http URL as a path', function () {
    expect(fn () => new MediaLocation('public', 'http://cdn.example.test/file.jpg'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a protocol-relative URL as a path', function () {
    expect(fn () => new MediaLocation('public', '//cdn.example.test/file.jpg'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a /storage/ prefixed path', function () {
    expect(fn () => new MediaLocation('public', '/storage/posts/file.jpg'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects the bare /storage path', function () {
    expect(fn () => new MediaLocation('public', '/storage'))
        ->toThrow(InvalidArgumentException::class);
});
