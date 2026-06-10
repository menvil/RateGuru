<?php

use App\Enums\ImportProvider;
use App\Support\Import\Adapters\OpenGraphImportAdapter;
use Illuminate\Support\Facades\Http;

it('creates import preview from open graph page', function () {
    Http::fake([
        'example.com/page' => Http::response(
            '<head><meta property="og:title" content="Imported Title"><meta property="og:image" content="https://example.com/image.jpg"></head>',
            200,
            ['Content-Type' => 'text/html']
        ),
    ]);

    $preview = app(OpenGraphImportAdapter::class)->preview('https://example.com/page');

    expect($preview->title)->toBe('Imported Title');
    expect($preview->imageUrl)->toBe('https://example.com/image.jpg');
    expect($preview->provider)->toBe(ImportProvider::OpenGraph);
});

it('returns preview without image when og image is missing', function () {
    Http::fake([
        'example.com/page' => Http::response(
            '<head><meta property="og:title" content="Title Only"></head>',
            200,
            ['Content-Type' => 'text/html']
        ),
    ]);

    $preview = app(OpenGraphImportAdapter::class)->preview('https://example.com/page');

    expect($preview->title)->toBe('Title Only');
    expect($preview->hasImage())->toBeFalse();
    expect($preview->warnings)->not->toBeEmpty();
});

it('falls back to html title when og tags are absent', function () {
    Http::fake([
        'example.com/page' => Http::response(
            '<html><head><title>Plain Title</title></head></html>',
            200,
            ['Content-Type' => 'text/html']
        ),
    ]);

    $preview = app(OpenGraphImportAdapter::class)->preview('https://example.com/page');

    expect($preview->title)->toBe('Plain Title');
});

it('rejects og image url that is unsafe', function () {
    Http::fake([
        'example.com/page' => Http::response(
            '<head><meta property="og:image" content="http://192.168.1.1/image.jpg"></head>',
            200,
            ['Content-Type' => 'text/html']
        ),
    ]);

    $preview = app(OpenGraphImportAdapter::class)->preview('https://example.com/page');

    expect($preview->hasImage())->toBeFalse();
    expect($preview->warnings)->not->toBeEmpty();
});
