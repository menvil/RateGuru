<?php

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\ProjectSettings;
use App\Models\TrackingLink;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * Analytics → Tracking links in a real browser: Create tracking link opening
 * the drawer, the address built as the link is picked and typed, Copy link
 * putting it on the clipboard, Create link opening the new link's details,
 * and View in Campaigns opening its campaign with the banner and its rows
 * marked; and the list and both drawers at each width, never wider than the
 * screen.
 */
beforeEach(function () {
    ProjectSettings::factory()->create();
    actingAs(User::factory()->admin()->create(['name' => 'Daria Kovaleva']));
    Game::factory()->status(GameStatus::Live)->create(['title' => 'Trust Your Taste', 'slug' => 'trust-your-taste']);

    // People who already came by the tags the link will carry.
    $recent = now()->subDays(2);

    foreach (range(1, 3) as $n) {
        analyticsVisit('reddit-'.$n, [
            'network_day' => $recent->toDateString(),
            'arrived_at' => $recent->toDateTimeString(),
            'utm_source' => 'reddit',
            'utm_medium' => 'organic_social',
            'utm_campaign' => 'versus_launch',
            'utm_content' => 'post_01',
            'source_key' => 'reddit:organic',
            'campaign_key' => 'tag:versus_launch',
            'creative_key' => 'post_01',
        ]);
    }
});

/** Opens an Analytics → Tracking links address and waits for the list it reads once it is in the browser (wire:init). */
function visitTrackingLinks(string $url, int $width = 1440): mixed
{
    $page = visitAdminEditor($url, $width);
    waitForScript($page, "document.querySelector('[data-testid=\"analytics-links\"]') !== null", true, 10.0);

    return $page;
}

it('creates a link, copies its address, opens its details and its campaign', function () {
    $page = visitTrackingLinks('/admin/analytics?tab=links');

    $page->click('[data-testid="analytics-link-create"]');
    waitForScript($page, "document.querySelector('[data-testid=\"analytics-link-drawer\"] .rg-admin-drawer') !== null", true, 10.0);

    $page->click('[aria-label="Destination type"] [role="radio"]:nth-child(2)');
    waitForScript($page, "document.querySelector('[aria-label=\"Site page\"]') !== null", true, 10.0);
    $page->click('[aria-label="Site page"] [role="radio"]:first-child');
    $page->click('[aria-label="Source"] [role="radio"]:nth-child(3)');
    $page->click('[aria-label="Medium"] [role="radio"]:nth-child(2)');
    $page->type('#rg-admin-tracking-link-campaign', 'versus_launch');
    $page->type('#rg-admin-tracking-link-content', 'post_01');
    waitForScript($page, "document.querySelector('[data-testid=\"analytics-link-url\"]').textContent.trim().endsWith('/?utm_source=reddit&utm_medium=organic_social&utm_campaign=versus_launch&utm_content=post_01')", true, 10.0);

    expect($page->script("document.querySelector('#rg-admin-tracking-link-name').value"))->toBe('Reddit · versus_launch · post_01')
        ->and($page->script("document.querySelector('[data-testid=\"analytics-link-foot\"]').textContent.trim()"))->toBe('Ready to create');

    // A headless browser may not write to the clipboard: what is written is kept for the test to read.
    $page->script("() => { Object.defineProperty(navigator.clipboard, 'writeText', { value: (text) => { window.copiedLink = text; return Promise.resolve() } }); return true; }");
    $page->click('[data-testid="analytics-link-copy"]');
    waitForScript($page, "document.body.textContent.includes('Link copied')", true, 10.0);

    expect((string) $page->script('window.copiedLink'))->toEndWith('/?utm_source=reddit&utm_medium=organic_social&utm_campaign=versus_launch&utm_content=post_01');

    $page->click('[data-testid="analytics-link-save"]');
    waitForScript($page, "document.querySelector('[data-testid=\"analytics-link-detail\"] .rg-admin-drawer') !== null", true, 10.0);

    $link = TrackingLink::query()->sole();

    expect($page->script("new URLSearchParams(location.search).get('show')"))->toBe((string) $link->id)
        ->and($page->script("document.querySelector('[data-testid=\"analytics-link-drawer\"]') === null"))->toBeTrue()
        ->and($page->script("document.querySelector('[data-testid=\"analytics-link-detail-url\"]').textContent.trim()"))->toBe((string) $page->script('window.copiedLink'))
        ->and($page->script("document.body.textContent.includes('Tracking link created. Visits with these tags already count for it.')"))->toBeTrue()
        ->and($page->script("document.querySelectorAll('[data-testid=\"analytics-link-row\"]').length"))->toBe(1);

    $page->click('[data-testid="analytics-link-detail-campaigns"]');
    waitForScript($page, "document.querySelector('[data-testid=\"analytics-link-banner\"]') !== null && document.querySelector('[data-testid=\"analytics-campaigns\"]') !== null", true, 15.0);

    expect($page->script("document.querySelector('[data-testid=\"analytics-link-banner\"]').textContent.replace(/\\s+/g, ' ').trim()"))
        ->toStartWith('Showing campaign performance for TL-'.$link->id.' (reddit · organic_social · versus_launch · post_01). Its row is marked.')
        ->and($page->script("[...document.querySelectorAll('.rg-admin-analytics__campaign-row--marked .rg-admin-analytics__campaign-name')].map((name) => name.textContent.trim())"))->toBe(['versus_launch', 'post_01'])
        ->and($page->script("new URLSearchParams(location.search).get('link')"))->toBe((string) $link->id);

    $page->click('[data-testid="analytics-link-back"]');
    waitForScript($page, "document.querySelector('[data-testid=\"analytics-links\"]') !== null", true, 10.0);

    expect($page->script("new URLSearchParams(location.search).get('tab')"))->toBe('links')
        ->and($page->script("new URLSearchParams(location.search).get('campaign')"))->toBeNull();

    $page->assertNoJavaScriptErrors();
});

it('draws the links and both drawers at every width, never wider than the screen', function (int $width) {
    $link = TrackingLink::factory()->create(['name' => 'Reddit · r/pics launch post', 'utm_campaign' => 'versus_launch', 'utm_content' => 'post_01']);

    $fits = fn (string $drawer): string => <<<JS
        (() => {
            const drawer = document.querySelector('[data-testid="{$drawer}"] .rg-admin-drawer').getBoundingClientRect()

            return {
                pageOverflows: document.documentElement.scrollWidth > window.innerWidth,
                drawerFits: drawer.width <= window.innerWidth && Math.round(drawer.right) === window.innerWidth,
            }
        })()
    JS;

    $page = visitTrackingLinks('/admin/analytics?tab=links&show='.$link->id, $width);
    waitForScript($page, "document.querySelector('[data-testid=\"analytics-link-detail\"] .rg-admin-drawer') !== null", true, 10.0);

    expect($page->script($fits('analytics-link-detail')))->toBe(['pageOverflows' => false, 'drawerFits' => true])
        ->and($page->script("document.querySelector('[data-testid=\"analytics-link-detail-metrics\"]') !== null"))->toBeTrue();

    $page = visitTrackingLinks('/admin/analytics?tab=links&edit='.$link->id, $width);
    waitForScript($page, "document.querySelector('[data-testid=\"analytics-link-drawer\"] .rg-admin-drawer') !== null", true, 10.0);

    expect($page->script($fits('analytics-link-drawer')))->toBe(['pageOverflows' => false, 'drawerFits' => true])
        ->and($page->script("document.querySelector('[data-testid=\"analytics-link-locked\"]') !== null"))->toBeTrue();

    $page->assertNoJavaScriptErrors();
})->with([1440, 1280, 1024, 390]);
