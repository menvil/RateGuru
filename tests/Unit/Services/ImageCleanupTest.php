<?php

use App\Services\Images\ImageCleanup;

it('has image cleanup helper placeholder', function () {
    $cleanup = app(ImageCleanup::class);

    expect($cleanup)->toBeInstanceOf(ImageCleanup::class);
});

it('has delete method with correct signature', function () {
    $reflection = new ReflectionClass(ImageCleanup::class);

    expect($reflection->hasMethod('delete'))->toBeTrue();

    $method = $reflection->getMethod('delete');
    expect($method->getNumberOfParameters())->toBe(2);
});

it('does not throw when path is null', function () {
    $cleanup = new ImageCleanup();

    expect(fn () => $cleanup->delete(null))->not->toThrow(Throwable::class);
});

it('does not throw when path is empty string', function () {
    $cleanup = new ImageCleanup();

    expect(fn () => $cleanup->delete(''))->not->toThrow(Throwable::class);
});

it('does not throw when path is valid', function () {
    $cleanup = new ImageCleanup();

    expect(fn () => $cleanup->delete('posts/1/image.jpg'))->not->toThrow(Throwable::class);
});
