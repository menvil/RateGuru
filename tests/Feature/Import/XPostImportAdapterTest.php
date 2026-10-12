<?php

use App\Enums\ImportProvider;
use App\Support\Import\Adapters\XPostImportAdapter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    bindFakeHostResolver([
        'x.com' => ['104.244.42.1'],
        'twitter.com' => ['104.244.42.65'],
        'mobile.twitter.com' => ['104.244.42.129'],
        'cdn.syndication.twimg.com' => ['151.101.0.157'],
        'pbs.twimg.com' => ['151.101.64.159'],
    ]);
    Http::preventStrayRequests();
});

/**
 * What the embed widget's endpoint returns for a post with one photo,
 * trimmed to the fields the adapter reads.
 *
 * @param  array<string, mixed>  $overrides
 */
function xSyndicatedPost(array $overrides = []): string
{
    return json_encode(array_replace([
        '__typename' => 'Tweet',
        'id_str' => '440322224407314432',
        'text' => "If only Bradley's arm was longer. Best photo ever. #oscars http://t.co/C9U5NOtGap",
        'user' => ['name' => 'The Ellen Show', 'screen_name' => 'TheEllenShow'],
        'mediaDetails' => [[
            'type' => 'photo',
            'indices' => [59, 81],
            'media_url_https' => 'https://pbs.twimg.com/media/BhxWutnCEAAtEQ6.jpg',
        ]],
    ], $overrides), JSON_THROW_ON_ERROR);
}

it('imports the first picture of a post on X at its large size, with the post text and its author', function () {
    Http::fake(['cdn.syndication.twimg.com/*' => Http::response(xSyndicatedPost(), 200, ['Content-Type' => 'application/json'])]);

    $preview = app(XPostImportAdapter::class)->preview('https://x.com/TheEllenShow/status/440322224407314432');

    expect($preview->provider)->toBe(ImportProvider::X)
        ->and($preview->sourceUrl)->toBe('https://x.com/TheEllenShow/status/440322224407314432')
        ->and($preview->imageUrl)->toBe('https://pbs.twimg.com/media/BhxWutnCEAAtEQ6?format=jpg&name=large')
        ->and($preview->title)->toBe('The Ellen Show (@TheEllenShow) on X')
        // The trailing t.co link is X's own link to the photo, not text.
        ->and($preview->description)->toBe("If only Bradley's arm was longer. Best photo ever. #oscars");

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://cdn.syndication.twimg.com/tweet-result?')
        && $request['id'] === '440322224407314432'
        && $request['token'] !== '');
});

it('finds the post in every form a link to it takes', function (string $url) {
    Http::fake(['cdn.syndication.twimg.com/*' => Http::response(xSyndicatedPost(), 200)]);

    expect(app(XPostImportAdapter::class)->preview($url)->imageUrl)
        ->toBe('https://pbs.twimg.com/media/BhxWutnCEAAtEQ6?format=jpg&name=large');

    Http::assertSent(fn (Request $request): bool => $request['id'] === '440322224407314432');
})->with([
    'x.com' => 'https://x.com/TheEllenShow/status/440322224407314432',
    'twitter.com, opened on its photo' => 'https://twitter.com/TheEllenShow/status/440322224407314432/photo/1',
    'mobile, with a share query' => 'https://mobile.twitter.com/TheEllenShow/status/440322224407314432?s=20&t=abc',
    'web intent form' => 'https://x.com/i/web/status/440322224407314432',
]);

it('gives a post without media no picture, rather than its author\'s avatar', function () {
    Http::fake(['cdn.syndication.twimg.com/*' => Http::response(xSyndicatedPost([
        'text' => 'just setting up my twttr',
        'user' => ['name' => 'jack', 'screen_name' => 'jack'],
        'mediaDetails' => [],
    ]), 200)]);

    $preview = app(XPostImportAdapter::class)->preview('https://x.com/jack/status/20');

    expect($preview->hasImage())->toBeFalse()
        ->and($preview->title)->toBe('jack (@jack) on X')
        ->and($preview->description)->toBe('just setting up my twttr');
});

it('takes a video\'s poster frame when the post has no photo', function () {
    Http::fake(['cdn.syndication.twimg.com/*' => Http::response(xSyndicatedPost([
        'mediaDetails' => [[
            'type' => 'video',
            'indices' => [59, 81],
            'media_url_https' => 'https://pbs.twimg.com/ext_tw_video_thumb/1/pu/img/poster.jpg',
        ]],
    ]), 200)]);

    expect(app(XPostImportAdapter::class)->preview('https://x.com/TheEllenShow/status/440322224407314432')->imageUrl)
        ->toBe('https://pbs.twimg.com/ext_tw_video_thumb/1/pu/img/poster.jpg');
});

it('falls back to the post page\'s Open Graph picture when the post data cannot be had', function (Closure $syndication) {
    Http::fake([
        'cdn.syndication.twimg.com/*' => $syndication,
        'x.com/*' => Http::response(
            '<head><meta property="og:title" content="The Ellen Show on X"><meta property="og:image" content="https://pbs.twimg.com/media/BhxWutnCEAAtEQ6?format=webp&amp;name=large"></head>',
            200,
            ['Content-Type' => 'text/html'],
        ),
    ]);

    $preview = app(XPostImportAdapter::class)->preview('https://x.com/TheEllenShow/status/440322224407314432');

    expect($preview->imageUrl)->toBe('https://pbs.twimg.com/media/BhxWutnCEAAtEQ6?format=webp&name=large')
        ->and($preview->title)->toBe('The Ellen Show on X');
})->with([
    'not found' => fn () => fn () => Http::response('', 404),
    'not the post JSON' => fn () => fn () => Http::response('<html>blocked</html>', 200),
]);

it('reads a link that is not a post from its page, without taking an avatar for its picture', function () {
    Http::fake([
        'x.com/*' => Http::response(
            '<head><meta property="og:title" content="The Ellen Show (@TheEllenShow) on X"><meta property="og:image" content="https://pbs.twimg.com/profile_images/1909090851592273920/UutmZxGI_200x200.jpg"></head>',
            200,
            ['Content-Type' => 'text/html'],
        ),
    ]);

    $preview = app(XPostImportAdapter::class)->preview('https://x.com/TheEllenShow');

    expect($preview->hasImage())->toBeFalse()
        ->and($preview->title)->toBe('The Ellen Show (@TheEllenShow) on X');

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'cdn.syndication.twimg.com'));
});
