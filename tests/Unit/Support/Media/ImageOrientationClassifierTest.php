<?php

use App\Enums\ImageOrientation;
use App\Support\Media\ImageOrientationClassifier;

it('classifies a tall image as portrait', function () {
    expect((new ImageOrientationClassifier)->classify(600, 1000))->toBe(ImageOrientation::Portrait);
});

it('classifies a wide image as landscape', function () {
    expect((new ImageOrientationClassifier)->classify(1000, 600))->toBe(ImageOrientation::Landscape);
});

it('classifies an equal image as square', function () {
    expect((new ImageOrientationClassifier)->classify(1000, 1000))->toBe(ImageOrientation::Square);
});

it('classifies the portrait boundary ratio as square, not portrait', function () {
    // 850 / 1000 = 0.85, exactly at the portrait threshold — not strictly less than it.
    expect((new ImageOrientationClassifier)->classify(850, 1000))->toBe(ImageOrientation::Square);
});

it('classifies the landscape boundary ratio as square, not landscape', function () {
    // 1180 / 1000 = 1.18, exactly at the landscape threshold — not strictly greater than it.
    expect((new ImageOrientationClassifier)->classify(1180, 1000))->toBe(ImageOrientation::Square);
});

it('rejects a zero width', function () {
    expect(fn () => (new ImageOrientationClassifier)->classify(0, 1000))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a zero height', function () {
    expect(fn () => (new ImageOrientationClassifier)->classify(1000, 0))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a negative width', function () {
    expect(fn () => (new ImageOrientationClassifier)->classify(-100, 1000))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a negative height', function () {
    expect(fn () => (new ImageOrientationClassifier)->classify(1000, -100))
        ->toThrow(InvalidArgumentException::class);
});
