<?php

use App\Enums\MediaResizeMode;
use App\Enums\MediaVariantName;
use App\Services\Media\MediaVariantSpecification;

it('defaults outputMimeType to null, meaning "same as source"', function () {
    $spec = new MediaVariantSpecification(MediaVariantName::PostFeed640, 640, 1280, MediaResizeMode::Contain, 82);

    expect($spec->outputMimeType)->toBeNull();
});

it('never considers a contain spec an upscale, regardless of source size', function () {
    $spec = new MediaVariantSpecification(MediaVariantName::PostFeed640, 640, 1280, MediaResizeMode::Contain, 82);

    expect($spec->wouldUpscale(10, 10))->toBeFalse()
        ->and($spec->wouldUpscale(640, 1280))->toBeFalse()
        ->and($spec->wouldUpscale(4000, 3000))->toBeFalse();
});

it('considers a cover-square spec an upscale when either source dimension is smaller than the target', function (int $srcW, int $srcH, bool $expected) {
    $spec = new MediaVariantSpecification(MediaVariantName::Avatar128, 128, 128, MediaResizeMode::CoverSquare, 82);

    expect($spec->wouldUpscale($srcW, $srcH))->toBe($expected);
})->with([
    'both smaller' => [100, 100, true],
    'width smaller only' => [100, 200, true],
    'height smaller only' => [200, 100, true],
    'exact fit' => [128, 128, false],
    'both larger' => [200, 200, false],
]);

it('considers a cover spec an upscale when either source dimension is smaller than its own target, using the non-square open graph bounds', function (int $srcW, int $srcH, bool $expected) {
    $spec = new MediaVariantSpecification(MediaVariantName::OpenGraph, 1200, 630, MediaResizeMode::Cover, 82, outputMimeType: 'image/jpeg');

    expect($spec->wouldUpscale($srcW, $srcH))->toBe($expected);
})->with([
    'both smaller' => [800, 400, true],
    'width smaller only' => [1000, 900, true],
    'height smaller only' => [1600, 500, true],
    'exact fit' => [1200, 630, false],
    'both larger' => [2400, 1600, false],
]);
