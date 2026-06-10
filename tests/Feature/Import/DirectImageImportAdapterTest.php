<?php

use App\Exceptions\Import\ImportFetchException;
use App\Support\Import\Adapters\DirectImageImportAdapter;
use Illuminate\Support\Facades\Http;

it('detects direct image urls from content type', function () {
    Http::fake([
        'example.com/image.jpg' => Http::response('fake-image-content', 200, [
            'Content-Type' => 'image/jpeg',
        ]),
    ]);

    $preview = app(DirectImageImportAdapter::class)->preview('https://example.com/image.jpg');

    expect($preview->imageUrl)->toBe('https://example.com/image.jpg');
    expect($preview->hasImage())->toBeTrue();
    expect($preview->provider)->toBe('direct_image');
});

it('rejects unsupported mime type', function () {
    Http::fake([
        'example.com/file.pdf' => Http::response('pdf-content', 200, [
            'Content-Type' => 'application/pdf',
        ]),
    ]);

    app(DirectImageImportAdapter::class)->preview('https://example.com/file.pdf');
})->throws(ImportFetchException::class);

it('returns preview with source url as title fallback', function () {
    Http::fake([
        'example.com/photo.png' => Http::response('fake-image-content', 200, [
            'Content-Type' => 'image/png',
        ]),
    ]);

    $preview = app(DirectImageImportAdapter::class)->preview('https://example.com/photo.png');

    expect($preview->sourceUrl)->toBe('https://example.com/photo.png');
});

it('rejects image exceeding max image bytes', function () {
    $oversized = str_repeat('x', config('import.max_image_bytes') + 1);

    Http::fake([
        'example.com/big.jpg' => Http::response($oversized, 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Length' => strlen($oversized),
        ]),
    ]);

    app(DirectImageImportAdapter::class)->preview('https://example.com/big.jpg');
})->throws(ImportFetchException::class);
