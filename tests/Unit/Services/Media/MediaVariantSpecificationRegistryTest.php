<?php

use App\Enums\MediaKind;
use App\Enums\MediaResizeMode;
use App\Enums\MediaVariantName;
use App\Services\Media\MediaVariantSpecification;
use App\Services\Media\MediaVariantSpecificationRegistry;

it('returns the three contain post-image variants followed by the open graph cover variant', function () {
    $specs = (new MediaVariantSpecificationRegistry)->for(MediaKind::PostImage);

    expect($specs)->toHaveCount(4);

    $names = array_map(fn (MediaVariantSpecification $spec): MediaVariantName => $spec->name, $specs);

    expect($names)->toBe([
        MediaVariantName::PostFeed640,
        MediaVariantName::PostFeed1280,
        MediaVariantName::PostDetail1920,
        MediaVariantName::OpenGraph,
    ]);

    $containSpecs = array_slice($specs, 0, 3);

    foreach ($containSpecs as $spec) {
        expect($spec->mode)->toBe(MediaResizeMode::Contain)
            ->and($spec->maxWidth)->toBeGreaterThan(0)
            ->and($spec->maxHeight)->toBeGreaterThan(0)
            ->and($spec->quality)->toBeGreaterThan(0)->toBeLessThanOrEqual(100)
            ->and($spec->outputMimeType)->toBeNull();
    }

    $widths = array_map(fn (MediaVariantSpecification $spec): int => $spec->maxWidth, $containSpecs);
    expect($widths)->toBe([640, 1280, 1920]);
});

it('returns the open graph variant last, in cover mode, at the exact configured share dimensions', function () {
    $specs = (new MediaVariantSpecificationRegistry)->for(MediaKind::PostImage);

    $openGraph = end($specs);

    // This is a plain, non-bootstrapped Unit test (no Laravel container), so
    // config/share.php is read directly rather than via the config() helper
    // — still cross-checking the two independently-declared 1200x630/jpeg
    // values so they can't silently drift apart.
    $shareConfig = require dirname(__DIR__, 4).'/config/share.php';

    expect($openGraph->name)->toBe(MediaVariantName::OpenGraph)
        ->and($openGraph->mode)->toBe(MediaResizeMode::Cover)
        ->and($openGraph->maxWidth)->toBe($shareConfig['open_graph']['width'])
        ->and($openGraph->maxHeight)->toBe($shareConfig['open_graph']['height'])
        ->and($openGraph->outputMimeType)->toBe($shareConfig['open_graph']['mime_type'])
        ->and($openGraph->quality)->toBeGreaterThan(0)->toBeLessThanOrEqual(100);
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
