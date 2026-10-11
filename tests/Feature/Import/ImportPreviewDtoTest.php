<?php

use App\Enums\ImportProvider;
use App\Support\Import\ImportPreview;

it('reports unsupported when unsupported reason is set', function () {
    $preview = new ImportPreview(
        provider: ImportProvider::Instagram,
        sourceUrl: 'https://www.instagram.com/p/abc',
        unsupportedReason: 'Provider is not accessible without authentication.',
    );

    expect($preview->isSupported())->toBeFalse();
    expect($preview->hasImage())->toBeFalse();
});

it('is supported when no unsupported reason set', function () {
    $preview = new ImportPreview(
        provider: ImportProvider::OpenGraph,
        sourceUrl: 'https://example.com/page',
        title: 'Some Title',
    );

    expect($preview->isSupported())->toBeTrue();
});

it('has image returns false when image url is null', function () {
    $preview = new ImportPreview(
        provider: ImportProvider::OpenGraph,
        sourceUrl: 'https://example.com/page',
    );

    expect($preview->hasImage())->toBeFalse();
});
