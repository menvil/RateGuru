<?php

use App\Models\Post;
use App\Models\ProjectSettings;
use Tests\Browser\Support\MobileViewports;

it('opens over the feed and closes in both post detail modes on mobile', function (bool $overlayMode) {
    ProjectSettings::factory()->create([
        'feature_flags' => ['post_detail_overlay_mode' => $overlayMode],
    ]);
    Post::factory()->published()->create([
        'title' => 'Mobile overlay browser post',
    ]);

    $page = visit(route('feed'))
        ->resize(...MobileViewports::MOBILE)
        ->wait(0.3);

    $scrollBefore = $page->script('window.scrollY');

    $page
        ->click('[data-testid="post-card"]')
        ->waitForText('Mobile overlay browser post')
        ->assertVisible('[data-testid="post-detail-overlay"]')
        ->wait(0.3);

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

    $page
        ->click('[data-testid="post-detail-overlay"] [data-testid="post-detail-close"]')
        ->wait(0.4);

    expect($page->script(
        'document.querySelector(\'[data-testid="post-detail-overlay"]\').classList.contains(\'translate-x-full\')'
    ))->toBeTrue();
})->with([
    'split desktop mode' => false,
    'global overlay mode' => true,
]);
