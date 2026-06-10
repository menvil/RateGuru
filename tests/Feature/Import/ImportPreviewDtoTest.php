<?php

use App\Support\Import\ImportPreview;

it('creates import preview dto', function () {
    $preview = new ImportPreview(
        provider: 'open_graph',
        sourceUrl: 'https://example.com/page',
        title: 'Title',
        description: 'Description',
        imageUrl: 'https://example.com/image.jpg',
        warnings: [],
    );

    expect($preview->title)->toBe('Title');
    expect($preview->hasImage())->toBeTrue();
});

it('reports unsupported when unsupported reason is set', function () {
    $preview = new ImportPreview(
        provider: 'instagram',
        sourceUrl: 'https://www.instagram.com/p/abc',
        unsupportedReason: 'Provider is not accessible without authentication.',
    );

    expect($preview->isSupported())->toBeFalse();
    expect($preview->hasImage())->toBeFalse();
});

it('is supported when no unsupported reason set', function () {
    $preview = new ImportPreview(
        provider: 'open_graph',
        sourceUrl: 'https://example.com/page',
        title: 'Some Title',
    );

    expect($preview->isSupported())->toBeTrue();
});

it('has image returns false when image url is null', function () {
    $preview = new ImportPreview(
        provider: 'open_graph',
        sourceUrl: 'https://example.com/page',
    );

    expect($preview->hasImage())->toBeFalse();
});

it('stores warnings array', function () {
    $preview = new ImportPreview(
        provider: 'open_graph',
        sourceUrl: 'https://example.com/page',
        warnings: ['No image found.'],
    );

    expect($preview->warnings)->toHaveCount(1);
    expect($preview->warnings[0])->toBe('No image found.');
});
