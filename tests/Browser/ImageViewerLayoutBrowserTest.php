<?php

use App\Models\Post;
use App\Models\ProjectSettings;
use Tests\Browser\Support\ImageFixtures;
use Tests\Browser\Support\MobileViewports;

/*
 * The fullscreen image viewer is a page-level dialog, and the sticky app
 * header sits above those. It used to be centred in the whole screen, so on
 * a laptop its title row and close button slid under the header. It is now
 * laid out in the room below the header: the gap above it equals the gap
 * below it, at every screen size and wherever it is opened from.
 *
 * resize() has taken effect — layout and media queries included — by the
 * time it returns, so a click follows it directly. Only an open viewer reacts
 * to the window's resize event, which fires later.
 */

afterEach(function () {
    ImageFixtures::cleanup();
});

/** The viewer on screen, as a JavaScript expression. */
const VISIBLE_IMAGE_VIEWER = <<<'JS'
    [...document.querySelectorAll('[data-modal-below-header]')]
        .find((el) => getComputedStyle(el).display !== 'none' && el.getBoundingClientRect().height > 0)
JS;

/**
 * Waits until the viewer is open, measured, showing its image and done fading
 * in — however long that takes here. The page fonts are in too: the title row
 * is part of what the viewer measures.
 */
function waitForImageViewer(mixed $page): void
{
    $viewer = VISIBLE_IMAGE_VIEWER;

    waitForScript($page, <<<JS
        (() => {
            const viewer = {$viewer};
            const image = viewer?.querySelector('img');

            return Boolean(viewer)
                && viewer.style.getPropertyValue('--rg-modal-height') !== ''
                && Boolean(image) && image.complete && image.naturalHeight > 0
                && getComputedStyle(viewer).opacity === '1'
                && document.fonts.status === 'loaded';
        })()
    JS);
}

/**
 * @return array{headerBottom: int, viewport: int, panelTop: int, panelBottom: int, closeTop: int, closeBottom: int, imageTop: int, imageBottom: int, scrolls: bool}
 */
function imageViewerGeometry(mixed $page): array
{
    waitForImageViewer($page);

    $viewer = VISIBLE_IMAGE_VIEWER;

    return $page->script(<<<JS
        (() => {
            const viewer = {$viewer};
            const header = document.querySelector('[data-app-header]').getBoundingClientRect();
            const panel = viewer.querySelector('[data-modal-panel]').getBoundingClientRect();
            const close = viewer.querySelector('[data-testid="modal-close"]').getBoundingClientRect();
            const image = viewer.querySelector('img').getBoundingClientRect();

            return {
                headerBottom: Math.round(header.bottom),
                viewport: window.innerHeight,
                panelTop: Math.round(panel.top),
                panelBottom: Math.round(panel.bottom),
                closeTop: Math.round(close.top),
                closeBottom: Math.round(close.bottom),
                imageTop: Math.round(image.top),
                imageBottom: Math.round(image.bottom),
                scrolls: viewer.scrollHeight > viewer.clientHeight + 1,
            };
        })()
    JS);
}

/** @param array<string, int|bool> $geometry */
function expectViewerBelowHeader(array $geometry): void
{
    $above = $geometry['panelTop'] - $geometry['headerBottom'];
    $below = $geometry['viewport'] - $geometry['panelBottom'];

    expect($geometry['closeTop'])->toBeGreaterThanOrEqual($geometry['headerBottom'], 'the close button is under the header')
        ->and($above)->toBeGreaterThanOrEqual(16, 'the viewer touches the header')
        ->and(abs($above - $below))->toBeLessThanOrEqual(1, "the gap above ({$above}px) differs from the gap below ({$below}px)")
        ->and($geometry['imageTop'])->toBeGreaterThanOrEqual($geometry['panelTop'])
        ->and($geometry['imageBottom'])->toBeLessThanOrEqual($geometry['panelBottom'])
        ->and($geometry['scrolls'])->toBeFalse('the viewer has to be scrolled to be seen whole');
}

function openDrawerImage(mixed $page): void
{
    $button = '[...document.querySelectorAll(\'[data-testid="post-drawer-image-open"]\')].find((el) => el.offsetParent !== null)';

    // The drawer has the post and has stopped sliding in: a viewer opened
    // from a panel is laid out against that panel.
    waitForScript($page, <<<JS
        (() => {
            const button = {$button};

            if (! button) {
                return false;
            }

            for (let el = button; el; el = el.parentElement) {
                if (el.getAnimations().some((animation) => animation.playState !== 'finished')) {
                    return false;
                }
            }

            return true;
        })()
    JS);

    $page->script("{$button}.click()");
}

dataset('screens', [
    '15-inch laptop, browser chrome included' => [[1440, 790]],
    'small laptop' => [[1280, 720]],
    'laptop' => [[1440, 900]],
    'large desktop' => [[1728, 1000]],
    'tablet' => [MobileViewports::TABLET],
    'phone' => [MobileViewports::SMALL_MOBILE],
]);

it('keeps equal gaps below the header and above the bottom of the screen in the feed', function (array $screen, array $image) {
    Post::factory()->published()->create([
        'title' => 'Viewer Layout Post',
        'image_asset_id' => ImageFixtures::write(...$image)->id,
    ]);

    $page = visit(route('feed'))
        ->resize(...$screen)
        ->click('[data-testid="post-card-image-open"]');

    waitForImageViewer($page);

    $page->assertVisible('[data-testid="post-card-fullscreen-image"]');

    expectViewerBelowHeader(imageViewerGeometry($page));
})->with('screens')->with([
    'portrait' => [ImageFixtures::PORTRAIT_9X16],
    'panorama' => [ImageFixtures::PANORAMA],
]);

it('keeps the same layout on the post page', function (array $screen) {
    $post = Post::factory()->published()->create([
        'title' => 'Viewer Layout Post',
        'image_asset_id' => ImageFixtures::write(...ImageFixtures::PORTRAIT_9X16)->id,
    ]);

    $page = visit(route('posts.show', $post))
        ->resize(...$screen)
        ->click('[data-testid="post-show-image-open"]');

    waitForImageViewer($page);

    $page->assertVisible('[data-testid="post-fullscreen-image"]');

    expectViewerBelowHeader(imageViewerGeometry($page));
})->with('screens');

it('keeps the same layout when opened from the post drawer', function (bool $overlay, array $screen) {
    if ($overlay) {
        ProjectSettings::factory()->create([
            'feature_flags' => ['post_detail_overlay_mode' => true],
        ]);
    }

    Post::factory()->published()->create([
        'title' => 'Viewer Layout Post',
        'image_asset_id' => ImageFixtures::write(...ImageFixtures::PORTRAIT_9X16)->id,
    ]);

    $page = visit(route('feed'))->resize(...$screen);

    if ($screen[0] < 1024) {
        // Below the desktop breakpoint the post opens in the overlay drawer.
        waitForPostDetailOverlay($page);
    }

    $page->click('[data-testid="post-card-title"]');

    openDrawerImage($page);

    expectViewerBelowHeader(imageViewerGeometry($page));
})->with([
    'split view' => [false],
    'overlay panel' => [true],
])->with([
    '15-inch laptop, browser chrome included' => [[1440, 790]],
    'phone' => [MobileViewports::SMALL_MOBILE],
]);

it('follows the header when the mobile search row makes it taller', function () {
    Post::factory()->published()->create([
        'title' => 'Viewer Layout Post',
        'image_asset_id' => ImageFixtures::write(...ImageFixtures::PORTRAIT_9X16)->id,
    ]);

    $page = visit(route('feed', ['search' => 'Viewer Layout']))
        ->resize(...MobileViewports::SMALL_MOBILE)
        ->assertVisible('[data-testid="mobile-search-row"]')
        ->click('[data-testid="post-card-image-open"]');

    $geometry = imageViewerGeometry($page);

    expect($geometry['headerBottom'])->toBeGreaterThan(100);
    expectViewerBelowHeader($geometry);
});

it('makes room for a title that wraps onto several lines', function () {
    Post::factory()->published()->create([
        'title' => 'A very long post title that keeps going and going so that the viewer has to wrap it onto more than one line on a narrow screen',
        'image_asset_id' => ImageFixtures::write(...ImageFixtures::PORTRAIT_9X16)->id,
    ]);

    $page = visit(route('feed'))
        ->resize(...MobileViewports::SMALL_MOBILE)
        ->click('[data-testid="post-card-image-open"]');

    expectViewerBelowHeader(imageViewerGeometry($page));
});

it('follows the window when it is resized while the viewer is open', function () {
    Post::factory()->published()->create([
        'title' => 'Viewer Layout Post',
        'image_asset_id' => ImageFixtures::write(...ImageFixtures::PORTRAIT_9X16)->id,
    ]);

    $page = visit(route('feed'))
        ->resize(1440, 900)
        ->click('[data-testid="post-card-image-open"]');

    waitForImageViewer($page);

    $viewerHeight = VISIBLE_IMAGE_VIEWER.'.style.getPropertyValue("--rg-modal-height")';
    $heightBefore = $page->script($viewerHeight);

    $page->resize(1280, 640);

    // The viewer measures itself again on the window's resize event, which
    // fires only after resize() has returned.
    waitForScript($page, "{$viewerHeight} !== ".json_encode($heightBefore));

    expectViewerBelowHeader(imageViewerGeometry($page));
});

it('still closes with its close button', function () {
    Post::factory()->published()->create([
        'title' => 'Viewer Layout Post',
        'image_asset_id' => ImageFixtures::write(...ImageFixtures::PORTRAIT_9X16)->id,
    ]);

    $page = visit(route('feed'))
        ->resize(1440, 790)
        ->click('[data-testid="post-card-image-open"]');

    waitForImageViewer($page);

    $page->assertVisible('[data-testid="post-card-fullscreen-image"]')
        ->click('[data-modal-below-header] [data-testid="modal-close"]');

    waitForScript($page, 'document.querySelector(\'[data-testid="post-card-fullscreen-image"]\').getBoundingClientRect().height', 0);
});
