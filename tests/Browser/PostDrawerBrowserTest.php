<?php

use App\Livewire\Feed\PostDrawer;
use App\Models\Post;
use App\Models\ProjectSettings;
use Tests\Browser\Support\MobileViewports;

it('opens post drawer from feed post card', function () {
    Post::factory()->published()->create([
        'title' => 'Browser Drawer Test Post',
    ]);

    visit(route('feed'))
        ->assertSee('Browser Drawer Test Post')
        ->click('[data-testid="post-card"]')
        ->waitForText('Browser Drawer Test Post')
        ->assertVisible('[data-testid="post-detail-column"] [data-testid="post-drawer"]')
        ->assertSeeIn('[data-testid="post-detail-column"] [data-testid="post-drawer-title"]', 'Browser Drawer Test Post');
});

it('keeps the sliding post drawer bounded on desktop so the feed remains visible', function () {
    ProjectSettings::factory()->create([
        'feature_flags' => ['post_detail_overlay_mode' => true],
    ]);
    Post::factory()->published()->create([
        'title' => 'Bounded desktop drawer post',
    ]);

    $page = visit(route('feed'))
        ->resize(1440, 900)
        ->click('[data-testid="post-card"]')
        ->waitForText('Bounded desktop drawer post');

    // Open, not merely visible: a closed panel parks just off-screen, which a
    // visibility check counts as visible.
    waitForPostDetailOverlayOpen($page);

    $geometry = $page->script(<<<'JS'
        (() => {
            const panel = document.querySelector('[data-testid="post-detail-overlay"]').getBoundingClientRect();
            const card = document.querySelector('[data-testid="post-card"]').getBoundingClientRect();

            return {
                panelLeft: Math.round(panel.left),
                panelWidth: Math.round(panel.width),
                cardLeft: Math.round(card.left),
                viewport: window.innerWidth,
            };
        })()
    JS);

    expect($geometry['panelWidth'])->toBeLessThanOrEqual(1008)
        ->and($geometry['panelLeft'])->toBeGreaterThanOrEqual(430)
        ->and($geometry['cardLeft'])->toBeLessThan($geometry['panelLeft'])
        ->and($geometry['panelWidth'])->toBeLessThan($geometry['viewport']);
});

it('draws a selected post once: in the detail column on a desktop, in the overlay on a phone', function (array $screen, string $drawer) {
    // The split view has an inline detail column for a desktop and an overlay
    // for anything narrower, and only one of them is ever on screen. Both used
    // to load and draw every selected post.
    Post::factory()->published()->create(['title' => 'Drawn Once Post']);

    $drawn = new ArrayObject;
    Livewire\on('render', function (object $component) use ($drawn): void {
        if ($component instanceof PostDrawer && $component->postId !== null) {
            $drawn[] = $component->asOverlay ? 'overlay' : 'column';
        }
    });

    $page = visit(route('feed'))->resize(...$screen);
    waitForViewportSize($page, ...$screen);

    if ($drawer === 'overlay') {
        waitForPostDetailOverlay($page);
    }

    $page->click('[data-testid="post-card"]');

    if ($drawer === 'overlay') {
        waitForPostDetailOverlayOpen($page);
    }

    $shown = $drawer === 'overlay' ? '[data-testid="post-detail-overlay"]' : '[data-testid="post-detail-column"]';
    $page->assertSeeIn($shown.' [data-testid="post-drawer-title"]', 'Drawn Once Post');

    expect($drawn->getArrayCopy())->toBe([$drawer]);
})->with([
    'desktop' => [[1440, 900], 'column'],
    'phone' => [MobileViewports::MOBILE, 'overlay'],
]);
