<?php

use App\Enums\ImportProvider;
use App\Support\Import\Adapters\OpenGraphImportAdapter;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    bindFakeHostResolver();
});

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

it('resolves a relative og:image against the response finalUrl, not the originally requested url, after a redirect to a different directory', function () {
    Http::fake([
        'example.com/a/original' => Http::response('', 302, ['Location' => 'https://example.com/b/final']),
        'example.com/b/final' => Http::response(
            '<head><meta property="og:image" content="photo.jpg"></head>',
            200,
            ['Content-Type' => 'text/html']
        ),
    ]);

    $preview = app(OpenGraphImportAdapter::class)->preview('https://example.com/a/original');

    // Relative to /b/ (the final directory the redirect landed on), not
    // /a/ (the originally requested directory) -- proves relative
    // og:image resolution uses $response->finalUrl, not the input $url.
    expect($preview->imageUrl)->toBe('https://example.com/b/photo.jpg');
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
});
