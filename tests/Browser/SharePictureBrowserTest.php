<?php

use App\Models\Post;
use App\Support\Urls\PostUrl;
use Tests\Browser\Support\ImageFixtures;
use Tests\Browser\Support\MobileViewports;

/*
 * The share buttons for X, Telegram, WhatsApp and email hand over the post's
 * picture itself. A headless browser has neither a share sheet nor a
 * clipboard a test can read, so both are replaced by recorders before the
 * share panel opens; what is checked is what the page hands them.
 */

afterEach(function () {
    ImageFixtures::cleanup();
});

function sharedPicturePost(): Post
{
    return Post::factory()->published()->create([
        'title' => 'Harbour at dusk',
        'image_asset_id' => ImageFixtures::write(...ImageFixtures::LANDSCAPE_16X9)->id,
    ]);
}

/** Opens the post page's share panel and waits until its picture is fetched. */
function openSharePanelWithPicture(mixed $page, string $ready): void
{
    $page->click('[data-testid="post-show-share-btn"]');

    waitForScript($page, "(() => { const data = Alpine.\$data(document.querySelector('[data-testid=\"share-buttons\"]')); return {$ready}; })()", timeoutSeconds: 10.0);
}

it('copies the picture and offers to open X, where the page can put a picture on the clipboard', function () {
    $post = sharedPicturePost();

    $page = visit(route('posts.show', $post))->resize(1440, 1000);
    $page->script(<<<'JS'
        Object.defineProperty(navigator.clipboard, 'write', {
            configurable: true,
            value: async (items) => { window.copiedTypes = items.flatMap((item) => item.types); },
        });
    JS);

    openSharePanelWithPicture($page, 'data.clipboardPicture !== null');
    $page->click('[data-testid="share-x"]:visible');
    waitForScript($page, 'window.copiedTypes !== undefined');

    expect($page->script('window.copiedTypes'))->toBe(['image/png'])
        ->and($page->script('document.querySelector(\'[data-testid="share-picture-offer"]\').innerText'))->toContain('Picture copied. Open X and paste it in.')
        ->and($page->script('document.querySelector(\'[data-testid="share-picture-open"]\').href'))->toBe(route('posts.share', ['post' => $post, 'provider' => 'x']));
});

it('hands the picture to the share sheet, captioned with the post\'s link, where the sheet is where the apps are', function () {
    $post = sharedPicturePost();

    $page = visit(route('posts.show', $post))->resize(...MobileViewports::MOBILE);
    $page->script(<<<'JS'
        const matchMedia = window.matchMedia.bind(window);
        window.matchMedia = (query) => query === '(pointer: coarse)'
            ? { matches: true, media: query, onchange: null, addListener() {}, removeListener() {}, addEventListener() {}, removeEventListener() {}, dispatchEvent: () => false }
            : matchMedia(query);
        Object.defineProperty(navigator, 'canShare', { configurable: true, value: () => true });
        Object.defineProperty(navigator, 'share', {
            configurable: true,
            value: async (data) => { window.shared = { files: data.files.map((file) => [file.name, file.type]), text: data.text }; },
        });
    JS);

    openSharePanelWithPicture($page, 'data.picture !== null');
    $page->click('[data-testid="share-telegram"]:visible');
    waitForScript($page, 'window.shared !== undefined');

    $shared = $page->script('window.shared');

    expect($shared['files'])->toHaveCount(1)
        ->and($shared['files'][0][0])->toStartWith('harbour-at-dusk.')
        ->and($shared['files'][0][1])->toStartWith('image/')
        ->and($shared['text'])->toBe('Harbour at dusk '.app(PostUrl::class)->canonical($post))
        ->and($page->script('document.querySelector(\'[data-testid="share-picture-offer"]\').offsetParent'))->toBeNull();
});
