<?php

use App\Models\Post;
use App\Models\ProjectSettings;

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
        ->wait(0.2)
        ->click('[data-testid="post-card"]')
        ->waitForText('Bounded desktop drawer post')
        ->assertVisible('[data-testid="post-detail-overlay"]')
        ->wait(0.3);

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
