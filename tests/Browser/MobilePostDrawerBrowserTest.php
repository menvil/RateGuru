<?php

use App\Models\Post;
use App\Models\ProjectSettings;
use Tests\Browser\Support\MobileViewports;

it('renders the feed empty state square on mobile and rounded at the responsive breakpoint', function () {
    $page = visit(route('feed'))->resize(...MobileViewports::MOBILE);

    waitForViewportSize($page, ...MobileViewports::MOBILE);

    expect($page->script(<<<'JS'
        getComputedStyle(document.querySelector('[data-testid="feed-empty-state"]')).borderTopLeftRadius
    JS))->toBe('0px');

    $page->resize(768, 1024);

    waitForViewportSize($page, 768, 1024);

    expect($page->script(<<<'JS'
        getComputedStyle(document.querySelector('[data-testid="feed-empty-state"]')).borderTopLeftRadius
    JS))->not->toBe('0px');
});

it('opens over the feed and closes in both post detail modes on mobile', function (bool $overlayMode, ?string $locale) {
    ProjectSettings::factory()->create([
        'feature_flags' => ['post_detail_overlay_mode' => $overlayMode],
    ]);
    Post::factory()->published()->create([
        'title' => 'Mobile overlay browser post',
    ]);

    $page = visit(route('feed'));

    if ($locale !== null) {
        offerLocales([$locale]);
        $page = $page->withLocale($locale);
    }

    $page = $page->resize(...MobileViewports::MOBILE);

    waitForPostDetailOverlay($page);

    $scrollBefore = $page->script('window.scrollY');

    $page
        ->click('[data-testid="post-card"]')
        ->waitForText('Mobile overlay browser post');

    // Open, not merely visible: a closed panel parks just off-screen, which a
    // visibility check counts as visible.
    waitForPostDetailOverlayOpen($page);

    $geometry = $page->script(<<<'JS'
        (() => {
            const panel = document.querySelector('[data-testid="post-detail-overlay"]');
            const rect = panel.getBoundingClientRect();

            return {
                left: Math.round(rect.left),
                width: Math.round(rect.width),
                viewport: window.innerWidth,
                scrollY: window.scrollY,
            };
        })()
    JS);

    expect($geometry['left'])->toBeLessThanOrEqual(1)
        ->and($geometry['width'])->toBeGreaterThanOrEqual($geometry['viewport'] - 1)
        ->and(abs($geometry['scrollY'] - $scrollBefore))->toBeLessThanOrEqual(1);

    $page->click('[data-testid="post-detail-overlay"] [data-testid="post-detail-close"]');

    // The panel moves out the moment it is told to; the server's answer —
    // the panel rendered without the post — is what may not move it back.
    waitForScript($page, 'document.querySelector(\'[data-testid="post-detail-overlay"] [data-testid="post-drawer-title"]\') === null');
    waitForPostDetailOverlayClosed($page);

    // Off the screen past the end of the line it came in from: the right
    // edge on a page read left to right, the left one on a page read right
    // to left.
    $closed = $page->script(<<<'JS'
        (() => {
            const rect = document.querySelector('[data-testid="post-detail-overlay"]').getBoundingClientRect();

            return { left: Math.round(rect.left), right: Math.round(rect.right), viewport: window.innerWidth };
        })()
    JS);

    if ($locale === null) {
        expect($closed['left'])->toBeGreaterThanOrEqual($closed['viewport']);
    } else {
        expect($closed['right'])->toBeLessThanOrEqual(0);
    }
})->with([
    'split desktop mode' => false,
    'global overlay mode' => true,
])->with([
    'left to right' => null,
    'right to left' => 'ar',
]);
