<?php

use App\Actions\Import\ImportFromUrlAction;
use App\Enums\ImportProvider;
use App\Exceptions\Import\UnsafeImportUrlException;
use Illuminate\Support\Facades\Http;

it('imports preview from generic open graph url', function () {
    Http::fake([
        'example.com/page' => Http::response(
            '<head><meta property="og:title" content="Imported Title"></head>',
            200,
            ['Content-Type' => 'text/html']
        ),
    ]);

    $preview = app(ImportFromUrlAction::class)->handle('https://example.com/page');

    expect($preview->title)->toBe('Imported Title');
    expect($preview->provider)->toBe(ImportProvider::OpenGraph);
});

it('imports preview from direct image url', function () {
    Http::fake([
        'example.com/photo.jpg' => Http::response('fake-image', 200, [
            'Content-Type' => 'image/jpeg',
        ]),
    ]);

    $preview = app(ImportFromUrlAction::class)->handle('https://example.com/photo.jpg');

    expect($preview->imageUrl)->toBe('https://example.com/photo.jpg');
    expect($preview->provider)->toBe(ImportProvider::DirectImage);
});

it('returns unsupported preview when social provider cannot be imported', function () {
    Http::fake([
        'www.instagram.com/*' => Http::response('', 403),
    ]);

    $preview = app(ImportFromUrlAction::class)->handle('https://www.instagram.com/p/abc');

    expect($preview->isSupported())->toBeFalse();
    expect($preview->provider)->toBe(ImportProvider::Instagram);
});

it('rejects unsafe urls', function () {
    app(ImportFromUrlAction::class)->handle('http://localhost/test');
})->throws(UnsafeImportUrlException::class);
