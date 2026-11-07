<?php

use App\Livewire\Feed\PostDrawer;
use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\User;
use Tests\Browser\Support\ImageFixtures;
use Tests\Browser\Support\MobileViewports;

use function Pest\Laravel\actingAs;

afterEach(function () {
    ImageFixtures::cleanup();
});

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

it('leaves Enter and Space on a control inside a card to that control', function () {
    Post::factory()->published()->create([
        'title' => 'Keyboard Card Post',
        'image_asset_id' => ImageFixtures::write(...ImageFixtures::PORTRAIT_9X16)->id,
    ]);

    $page = visit(route('feed'))->assertSee('Keyboard Card Post');

    // Every post a card asks to open, counted as the card asks.
    $page->script("window.rgSelectedPosts = 0; window.addEventListener('select-post', () => window.rgSelectedPosts++)");

    $viewerOpen = 'document.querySelector(\'[data-testid="post-card-fullscreen-image"]\').getBoundingClientRect().height > 0';

    foreach (['Enter', 'Space'] as $key) {
        $page->keys('[data-testid="post-card-image-open"]', $key);

        waitForScript($page, $viewerOpen);

        $page->assertScript('window.rgSelectedPosts', 0)
            ->click('[data-modal-below-header] [data-testid="modal-close"]');

        waitForScript($page, $viewerOpen, false);
    }

    $page->keys('[data-testid="post-card"]', 'Enter');

    waitForScript($page, 'window.rgSelectedPosts', 1);
});

it('closes a post\'s menu with Escape, on the card, in the drawer and on the post page', function () {
    ProjectSettings::factory()->create([
        'feature_flags' => ['post_detail_overlay_mode' => true],
    ]);
    $post = Post::factory()->published()->create(['title' => 'A post with a menu']);

    actingAs(User::factory()->create());

    $menuShown = fn (string $button): string => "getComputedStyle(document.querySelector('{$button}').nextElementSibling).display !== 'none'";
    $closesWithEscape = function (mixed $page, string $button) use ($menuShown): void {
        $page->click($button);
        waitForScript($page, $menuShown($button));
        $page->keys($button, 'Escape');
        waitForScript($page, $menuShown($button), false);
    };

    $page = visit(route('feed'))->resize(1440, 900);

    $closesWithEscape($page, '[data-testid="post-actions-menu-'.$post->id.'"]');

    $page->click('[data-testid="post-card"]');
    waitForPostDetailOverlayOpen($page);

    $closesWithEscape($page, '[data-testid="post-detail-overlay"] [data-testid="post-drawer"] button[aria-label="'.__('ui.a11y.post_actions').'"]');

    $page->assertScript('document.querySelector(\'[data-testid="post-detail-overlay"]\').classList.contains(\'translate-x-0\')', true);

    $closesWithEscape(visit(route('posts.show', $post)), '[data-testid="post-show-actions-menu"]');
});

it('marks the card of the post that is open, and only that one', function (bool $overlay) {
    ProjectSettings::factory()->create([
        'feature_flags' => ['post_detail_overlay_mode' => $overlay],
    ]);
    $first = Post::factory()->published()->create(['title' => 'First marked post', 'published_at' => now()->subHour()]);
    $second = Post::factory()->published()->create(['title' => 'Second marked post', 'published_at' => now()]);

    $marked = 'JSON.stringify([...document.querySelectorAll(\'[data-testid="post-card"]\')].filter((card) => card.classList.contains(\'border-rg-accent\')).map((card) => Number(card.dataset.postId)))';

    $page = visit(route('feed'))->resize(1440, 900);

    $page->assertScript($marked, '[]');

    $page->keys('[data-testid="post-card"][data-post-id="'.$first->id.'"]', 'Enter');
    waitForScript($page, $marked, json_encode([$first->id]));

    if ($overlay) {
        // The open panel keeps the focus; closed, nothing is marked.
        $page->keys('[data-testid="post-detail-overlay"]', 'Escape');
        waitForScript($page, $marked, '[]');
    }

    $page->keys('[data-testid="post-card"][data-post-id="'.$second->id.'"]', 'Enter');
    waitForScript($page, $marked, json_encode([$second->id]));

    // The list drawn again — after a comment, say — keeps the mark where it
    // is. An attribute the server never draws is gone once it has been.
    $page->script("document.querySelectorAll('[data-testid=\"post-card\"]').forEach((card) => card.setAttribute('data-drawn-before', '')); Livewire.all().find((component) => component.name === 'feed.post-feed').\$wire.\$refresh()");
    waitForScript($page, "document.querySelectorAll('[data-drawn-before]').length", 0);
    $page->assertScript($marked, json_encode([$second->id]));
})->with(['split view' => false, 'overlay' => true]);

it('gives the focus back to the card a post was opened from when the drawer closes', function () {
    ProjectSettings::factory()->create([
        'feature_flags' => ['post_detail_overlay_mode' => true],
    ]);
    $post = Post::factory()->published()->create(['title' => 'A post opened from the keyboard']);
    $card = '[data-testid="post-card"][data-post-id="'.$post->id.'"]';

    $page = visit(route('feed'))->resize(1440, 900);

    $page->keys($card, 'Enter');
    waitForPostDetailOverlayOpen($page);
    // Both answers to the selection are in: the feed's and the drawer's own.
    waitForScript($page, 'document.activeElement?.dataset.testid', 'post-detail-overlay');
    $page->waitForText('A post opened from the keyboard');

    // The page and the drawer both answer a selection with post-selected, and
    // the second can arrive in a response of its own, with the panel focused.
    $page->script("window.dispatchEvent(new CustomEvent('post-selected', { detail: { postId: {$post->id} } }))");

    $page->keys('[data-testid="post-detail-overlay"]', 'Escape');

    waitForScript($page, 'document.activeElement?.dataset.postId', (string) $post->id);
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

/** The overlay panel is out, as a JavaScript expression. */
const OVERLAY_OPEN = 'document.querySelector(\'[data-testid="post-detail-overlay"]\').classList.contains(\'translate-x-0\')';

/** Opens a post in the overlay panel at a laptop's size, from its card's keyboard. */
function openPostInOverlay(mixed $page, Post $post): void
{
    $page->keys('[data-testid="post-card"][data-post-id="'.$post->id.'"]', 'Enter');
    waitForPostDetailOverlayOpen($page);
}

it('answers Escape only while it is open, and with a dialog over it lets the dialog take it', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['post_detail_overlay_mode' => true, 'show_share_buttons' => true]]);
    $post = Post::factory()->published()->create(['title' => 'A post with a share dialog']);
    $page = visit(route('feed'))->resize(1440, 900);
    waitForPostDetailOverlay($page);

    // Closed, Escape is nobody's: nothing is asked of the server.
    watchLivewireUpdates($page);
    $page->keys(':root', 'Escape');
    proveNothingHappensFor($page, 0.5, 'a closed panel answering Escape');
    expect(livewireUpdatesSinceWatching($page)['fetches'])->toBe(0);

    openPostInOverlay($page, $post);

    // The share dialog over the panel: Escape closes it, and only it.
    $page->click('[data-testid="post-detail-overlay"] [data-testid="post-drawer-share"]');
    $shareShown = "[...document.querySelectorAll('[data-testid=\"post-detail-overlay\"] [role=dialog][aria-modal=true]')].some((dialog) => dialog.checkVisibility())";
    waitForScript($page, $shareShown);
    $page->keys(':focus', 'Escape');
    waitForScript($page, $shareShown, false);
    $page->assertScript(OVERLAY_OPEN, true);

    // Then the panel itself, and the focus goes back to the card.
    $page->keys('[data-testid="post-detail-overlay"]', 'Escape');
    waitForScript($page, OVERLAY_OPEN, false);
    waitForScript($page, "document.activeElement.matches('[data-testid=\"post-card\"][data-post-id=\"{$post->id}\"]')");
});

it('closes on Escape from its follow and save buttons too', function (string $button) {
    ProjectSettings::factory()->create(['feature_flags' => ['post_detail_overlay_mode' => true, 'show_follow_buttons' => true, 'show_saved_posts' => true]]);
    $post = Post::factory()->published()->create(['title' => 'A post by someone to follow']);
    actingAs(User::factory()->create());
    $page = visit(route('feed'))->resize(1440, 900);

    openPostInOverlay($page, $post);
    $control = '[data-testid="post-detail-overlay"] [data-testid="'.$button.'"]';
    waitForScript($page, "!! document.querySelector('{$control}')");
    $page->script("document.querySelector('{$control}').focus()");
    $page->keys(':focus', 'Escape');

    waitForScript($page, OVERLAY_OPEN, false);
})->with(['follow-button', 'save-post-button']);

it('keeps the focus in the open panel', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['post_detail_overlay_mode' => true]]);
    $post = Post::factory()->published()->create(['title' => 'A post to tab through']);
    $page = visit(route('feed'))->resize(1440, 900);

    openPostInOverlay($page, $post);

    foreach (range(1, 25) as $tab) {
        $page->keys(':focus', 'Tab');
    }

    expect($page->script("document.querySelector('[data-testid=\"post-detail-overlay\"]').contains(document.activeElement)"))->toBeTrue();
});

it('scrolls the detail to the comments from its comments button without the feed jumping to the card', function () {
    Post::factory()->count(8)->published()->create();
    $page = visit(route('feed'))->resize(1440, 900);

    // The top card, so the page scrolled down has somewhere to jump back to.
    $top = $page->script('document.querySelector(\'[data-testid="post-card"]\').dataset.postId');
    // Selecting it scrolls the page to it: that scroll is over before the reader scrolls on.
    $page->script("window.selectionScrolled = false; window.addEventListener('scrollend', () => { window.selectionScrolled = true }, { once: true })");
    $page->click('[data-testid="post-card"][data-post-id="'.$top.'"]');
    waitForScript($page, "!! document.querySelector('[data-testid=\"post-detail-column\"] [data-testid=\"post-drawer-comments\"]') && window.selectionScrolled");
    $page->script('window.scrollTo(0, document.documentElement.scrollHeight)');
    waitForScript($page, 'window.scrollY + window.innerHeight >= document.documentElement.scrollHeight - 1');
    $scrolled = $page->script('window.scrollY');

    $page->script("document.querySelector('[data-testid=\"post-detail-column\"] [data-testid=\"post-drawer-comments\"]').click()");
    proveNothingHappensFor($page, 0.6, 'the feed scrolling back to the card');

    expect($page->script('window.scrollY'))->toBe($scrolled);
});

it('gives the page its scroll back when the window widens past the phone\'s panel', function () {
    $post = Post::factory()->published()->create(['title' => 'A post opened on a phone']);
    $page = visit(route('feed'))->resize(390, 844);
    waitForPostDetailOverlay($page);

    $page->click('[data-testid="post-card"][data-post-id="'.$post->id.'"]');
    waitForScript($page, "document.documentElement.classList.contains('overflow-hidden')");

    $page->resize(1440, 900);

    waitForScript($page, "document.documentElement.classList.contains('overflow-hidden')", false);
});
