<?php

use App\Actions\Import\ImportFromUrlAction;
use App\Enums\ImportProvider;
use App\Exceptions\Import\UnsafeImportUrlException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    bindFakeHostResolver();
});

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

it('reads a post on X from the embed widget\'s data rather than from its page', function () {
    bindFakeHostResolver([
        'x.com' => ['104.244.42.1'],
        'cdn.syndication.twimg.com' => ['151.101.0.157'],
        'pbs.twimg.com' => ['151.101.64.159'],
    ]);
    Http::preventStrayRequests();
    Http::fake(['cdn.syndication.twimg.com/*' => Http::response(json_encode([
        'text' => 'Dinner',
        'user' => ['name' => 'Cook', 'screen_name' => 'cook'],
        'mediaDetails' => [['type' => 'photo', 'media_url_https' => 'https://pbs.twimg.com/media/Dinner1.jpg']],
    ]), 200)]);

    $preview = app(ImportFromUrlAction::class)->handle('https://x.com/cook/status/123');

    expect($preview->provider)->toBe(ImportProvider::X)
        ->and($preview->imageUrl)->toBe('https://pbs.twimg.com/media/Dinner1?format=jpg&name=large');
});

it('takes an Instagram post\'s picture from its embed page rather than the cropped Open Graph one', function () {
    bindFakeHostResolver([
        'scontent.cdninstagram.com' => ['157.240.2.63'],
        'instagram.fsof11-1.fna.fbcdn.net' => ['157.240.2.50'],
    ]);
    Http::preventStrayRequests();
    Http::fake([
        'www.instagram.com/p/Dinner1234/embed/captioned/' => Http::response(
            '<img class="EmbeddedMediaImage" src="https://instagram.fsof11-1.fna.fbcdn.net/full.jpg" srcset="https://instagram.fsof11-1.fna.fbcdn.net/full.jpg 1080w">',
            200,
        ),
        'www.instagram.com/*' => Http::response('<meta property="og:image" content="https://scontent.cdninstagram.com/crop.jpg">', 200),
    ]);

    $preview = app(ImportFromUrlAction::class)->handle('https://www.instagram.com/p/Dinner1234/');

    expect($preview->provider)->toBe(ImportProvider::Instagram)
        ->and($preview->imageUrl)->toBe('https://instagram.fsof11-1.fna.fbcdn.net/full.jpg');
});

it('rejects unsafe urls', function () {
    app(ImportFromUrlAction::class)->handle('http://localhost/test');
})->throws(UnsafeImportUrlException::class);
