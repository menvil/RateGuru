<?php

use App\Enums\MediaKind;
use App\Enums\MediaResizeMode;
use App\Enums\MediaVariantName;
use App\Services\Media\MediaVariantSpecification;
use App\Services\Media\MediaVariantSpecificationRegistry;

it('returns the three contain post-image variants in ascending size order', function () {
    $specs = (new MediaVariantSpecificationRegistry)->for(MediaKind::PostImage);

    expect($specs)->toHaveCount(3);

    $names = array_map(fn (MediaVariantSpecification $spec): MediaVariantName => $spec->name, $specs);

    expect($names)->toBe([
        MediaVariantName::PostFeed640,
        MediaVariantName::PostFeed1280,
        MediaVariantName::PostDetail1920,
    ]);

    foreach ($specs as $spec) {
        expect($spec->mode)->toBe(MediaResizeMode::Contain)
            ->and($spec->maxWidth)->toBeGreaterThan(0)
            ->and($spec->maxHeight)->toBeGreaterThan(0)
            ->and($spec->quality)->toBeGreaterThan(0)->toBeLessThanOrEqual(100);
    }

    $widths = array_map(fn (MediaVariantSpecification $spec): int => $spec->maxWidth, $specs);
    expect($widths)->toBe([640, 1280, 1920]);
});

it('returns the two cover-square avatar variants', function () {
    $specs = (new MediaVariantSpecificationRegistry)->for(MediaKind::Avatar);

    expect($specs)->toHaveCount(2);

    $names = array_map(fn (MediaVariantSpecification $spec): MediaVariantName => $spec->name, $specs);

    expect($names)->toBe([MediaVariantName::Avatar128, MediaVariantName::Avatar256]);

    foreach ($specs as $spec) {
        expect($spec->mode)->toBe(MediaResizeMode::CoverSquare)
            ->and($spec->maxWidth)->toBe($spec->maxHeight);
    }
});

it('never returns duplicate variant names within a kind', function (MediaKind $kind) {
    $specs = (new MediaVariantSpecificationRegistry)->for($kind);
    $names = array_map(fn (MediaVariantSpecification $spec): string => $spec->name->value, $specs);

    expect($names)->toBe(array_unique($names));
})->with([MediaKind::PostImage, MediaKind::Avatar]);
