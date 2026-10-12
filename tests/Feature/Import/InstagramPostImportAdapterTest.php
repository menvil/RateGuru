<?php

use App\Enums\ImportProvider;
use App\Support\Import\Adapters\InstagramPostImportAdapter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    bindFakeHostResolver([
        'instagram.com' => ['157.240.2.174'],
        'scontent.cdninstagram.com' => ['157.240.2.63'],
        'instagram.fsof11-1.fna.fbcdn.net' => ['157.240.2.50'],
    ]);
    Http::preventStrayRequests();
});

/** The post page's Open Graph tags, with the small square crop Instagram puts in og:image. */
function instagramPostPage(): string
{
    return '<head>'
        .'<meta property="og:title" content="Just An Egg on Instagram: &quot;Let us set a world record&quot;">'
        .'<meta property="og:description" content="55M likes">'
        .'<meta property="og:image" content="https://scontent.cdninstagram.com/v/t51/egg_n.jpg?stp=c116.0.351.350a_dst-jpg&amp;oh=crop">'
        .'</head>';
}

/** The embed page's picture, in the sizes Instagram keeps, largest not first. */
function instagramEmbedPage(): string
{
    $url = fn (string $size): string => "https://instagram.fsof11-1.fna.fbcdn.net/v/t51/egg_n.jpg?stp=dst-jpg_e35_{$size}&amp;_nc_cat=104&amp;oh=full";

    return '<div class="Embed"><a class="EmbeddedMediaImageWrapper">'
        .'<img class="EmbeddedMediaImage" alt="Instagram post shared by &#064;world_record_egg" src="'.$url('s640x640').'"'
        .' srcset="'.$url('s640x640').' 640w,'.$url('tt6').' 1080w,'.$url('s320x320').' 320w"></a>'
        .'<div class="Caption">Let us set a world record</div></div>';
}

it('replaces the cropped Open Graph picture of an Instagram post with the largest one its embed page offers', function () {
    Http::fake([
        'www.instagram.com/p/BsOGulcndj-/embed/captioned/' => Http::response(instagramEmbedPage(), 200, ['Content-Type' => 'text/html']),
        'www.instagram.com/*' => Http::response(instagramPostPage(), 200, ['Content-Type' => 'text/html']),
    ]);

    $preview = app(InstagramPostImportAdapter::class)->preview('https://www.instagram.com/p/BsOGulcndj-/');

    expect($preview->imageUrl)->toBe('https://instagram.fsof11-1.fna.fbcdn.net/v/t51/egg_n.jpg?stp=dst-jpg_e35_tt6&_nc_cat=104&oh=full')
        ->and($preview->title)->toBe('Just An Egg on Instagram: "Let us set a world record"')
        ->and($preview->description)->toBe('55M likes')
        ->and($preview->provider)->toBe(ImportProvider::OpenGraph);
});

it('finds the post\'s embed page from every form a link to it takes', function (string $url, string $shortcode) {
    Http::fake([
        "www.instagram.com/p/{$shortcode}/embed/captioned/" => Http::response(instagramEmbedPage(), 200),
        'www.instagram.com/*' => Http::response(instagramPostPage(), 200),
        'instagram.com/*' => Http::response(instagramPostPage(), 200),
    ]);

    expect(app(InstagramPostImportAdapter::class)->preview($url)->imageUrl)->toContain('stp=dst-jpg_e35_tt6');
})->with([
    'a post' => ['https://www.instagram.com/p/BsOGulcndj-/', 'BsOGulcndj-'],
    'a post under its author, with a share query' => ['https://www.instagram.com/world_record_egg/p/BsOGulcndj-/?igsh=MWQ1', 'BsOGulcndj-'],
    'a reel' => ['https://www.instagram.com/reel/C6zLq3BrQ2T/', 'C6zLq3BrQ2T'],
    'a video' => ['https://instagram.com/tv/B_t8xY2nFQq', 'B_t8xY2nFQq'],
]);

it('keeps the Open Graph picture when the embed page has no picture or cannot be fetched', function (Closure $embed) {
    Http::fake([
        'www.instagram.com/p/BsOGulcndj-/embed/captioned/' => $embed,
        'www.instagram.com/*' => Http::response(instagramPostPage(), 200, ['Content-Type' => 'text/html']),
    ]);

    expect(app(InstagramPostImportAdapter::class)->preview('https://www.instagram.com/p/BsOGulcndj-/')->imageUrl)
        ->toBe('https://scontent.cdninstagram.com/v/t51/egg_n.jpg?stp=c116.0.351.350a_dst-jpg&oh=crop');
})->with([
    'no picture' => fn () => fn () => Http::response('<div class="Embed">This post is unavailable.</div>', 200),
    'refused' => fn () => fn () => Http::response('', 429),
]);

it('reads a link that is not a post from its page alone', function () {
    Http::fake(['www.instagram.com/*' => Http::response(instagramPostPage(), 200, ['Content-Type' => 'text/html'])]);

    app(InstagramPostImportAdapter::class)->preview('https://www.instagram.com/world_record_egg/');

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/embed/'));
});
