<?php

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\ProjectSettings;
use App\Models\TrafficCampaign;
use App\Models\TrafficSource;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * Analytics → Campaigns in a real browser: the tab at each width, the tree
 * scrolling inside its card and never the page; a campaign's row opening
 * its creatives and a creative's its games; a game's row opening the game
 * on the Games tab, of the same days and device; the sorting kept in the
 * address; and a paid source filtering the page.
 */
beforeEach(function () {
    ProjectSettings::factory()->create();
    actingAs(User::factory()->admin()->create());

    $this->game = Game::factory()->status(GameStatus::Live)->create(['title' => 'Trust Your Taste']);
    $this->exo = TrafficSource::factory()->create(['name' => 'ExoClick tests']);
    $this->campaign = TrafficCampaign::factory()->create(['source_id' => $this->exo->id, 'name' => 'US popunder']);
    $recent = now()->subDays(2);
    $day = ['network_day' => $recent->toDateString(), 'arrived_at' => $recent->toDateTimeString(), 'device' => 'desktop'];

    foreach (range(1, 6) as $n) {
        analyticsClick('paid-'.$n, $this->campaign, [...$day, 'creative_key' => $n <= 4 ? 'banner-1' : 'banner-2', 'game_id' => $this->game->id, 'spend' => '0.10']);
    }

    foreach (range(1, 3) as $n) {
        analyticsVisit('spring-'.$n, [...$day, 'source_key' => 'reddit:organic', 'campaign_key' => 'tag:spring', 'creative_key' => 'post-'.$n]);
    }
});

/** Opens an Analytics → Campaigns address and waits for the tree it reads once it is in the browser (wire:init). */
function visitAnalyticsCampaigns(string $url, int $width = 1440): mixed
{
    $page = visitAdminEditor($url, $width);
    waitForScript($page, "document.querySelector('[data-testid=\"analytics-campaigns\"]') !== null", true, 10.0);

    return $page;
}

/** The tree's rows as the browser shows them: indented by level, each name. */
function campaignTreeNames(mixed $page): array
{
    return $page->script("[...document.querySelectorAll('[data-testid=\"analytics-campaign-row\"]')].map((row) => '  '.repeat(Number(row.dataset.level)) + row.querySelector('.rg-admin-analytics__campaign-name').textContent.trim())");
}

it('draws the tab at every width, the tree scrolling inside its card, never the page', function (int $width) {
    $page = visitAnalyticsCampaigns('/admin/analytics?tab=campaigns', $width);

    expect($page->script("[...document.querySelectorAll('.rg-admin-analytics__tabs .rg-admin-tab')].map((tab) => tab.textContent.trim())"))->toBe(['Overview', 'Campaigns', 'Landings', 'Games', 'Experiments', 'Geography'])
        ->and(campaignTreeNames($page))->toBe(['US popunder', '  banner-1', '    Trust Your Taste', '  banner-2', 'spring'])
        ->and($page->script("[...document.querySelectorAll('[data-testid=\"analytics-paid-source\"] .rg-admin-analytics__paid-name')].map((name) => name.textContent.trim())"))->toBe(['ExoClick tests'])
        ->and($page->script(<<<'JS'
            (() => {
                const scroll = document.querySelector('[data-testid="analytics-campaigns"] .rg-admin-table__scroll')
                const grid = scroll.querySelector('[role="table"]')

                return {
                    pageOverflows: document.documentElement.scrollWidth > window.innerWidth,
                    treeInCard: (scroll.scrollWidth > scroll.clientWidth) === (grid.offsetWidth > scroll.clientWidth),
                }
            })()
        JS))->toBe(['pageOverflows' => false, 'treeInCard' => true]);

    $page->assertNoJavaScriptErrors();
})->with([1440, 1280, 1024, 390]);

it('opens a campaign’s creatives and a creative’s games, and closes them again', function () {
    $page = visitAnalyticsCampaigns('/admin/analytics?tab=campaigns');

    $page->click('[data-testid="analytics-campaign-row"][data-kind="campaign"]:nth-last-child(1) .rg-admin-analytics__campaign-name');
    waitForScript($page, "document.querySelectorAll('[data-testid=\"analytics-campaign-row\"]').length === 8", true, 10.0);

    expect(campaignTreeNames($page))->toBe(['US popunder', '  banner-1', '    Trust Your Taste', '  banner-2', 'spring', '  post-1', '  post-2', '  post-3']);

    $page->click('[data-testid="analytics-campaign-row"]:nth-child(2) .rg-admin-analytics__campaign-name');
    waitForScript($page, "document.querySelectorAll('[data-testid=\"analytics-campaign-row\"]').length === 5", true, 10.0);

    expect(campaignTreeNames($page))->toBe(['US popunder', 'spring', '  post-1', '  post-2', '  post-3'])
        ->and($page->script("document.querySelector('[data-testid=\"analytics-campaign-row\"][data-kind=\"campaign\"] .rg-admin-analytics__campaign-name').getAttribute('aria-expanded')"))->toBe('false');

    $page->assertNoJavaScriptErrors();
});

it('opens a game’s row on the Games tab, of the same days and device', function () {
    $page = visitAnalyticsCampaigns('/admin/analytics?tab=campaigns&period=7d&device=desktop&source=paid:'.$this->exo->id);

    $page->click('[data-testid="analytics-campaign-row"][data-kind="game"] .rg-admin-analytics__campaign-sub');
    waitForScript($page, "document.querySelector('[data-testid=\"game-analytics-filters\"]') !== null", true, 10.0);

    // The source stays behind: it doesn't apply there.
    expect($page->script('Object.fromEntries(new URLSearchParams(location.search))'))->toEqual(['tab' => 'games', 'game' => (string) $this->game->id, 'period' => '7d', 'device' => 'desktop']);

    $page->assertNoJavaScriptErrors();
});

it('keeps the sorting in the address, and filters the page by a paid source', function () {
    $page = visitAnalyticsCampaigns('/admin/analytics?tab=campaigns');

    $page->click('[data-testid="analytics-campaigns"] .rg-admin-segmented__option:nth-child(2)');
    waitForScript($page, "new URLSearchParams(location.search).get('csort')", 'registration', 10.0);

    $page->click('[data-testid="analytics-paid-source"] .rg-admin-analytics__paid-name');
    waitForScript($page, "new URLSearchParams(location.search).get('source')", 'paid:'.$this->exo->id, 10.0);

    expect($page->script("document.querySelector('[data-testid=\"analytics-source-chip\"]')?.textContent.trim()"))->toBe('Source: ExoClick tests · paid_ads')
        ->and($page->script("document.querySelector('[data-testid=\"analytics-paid-source\"] .rg-admin-analytics__paid-name').getAttribute('aria-pressed')"))->toBe('true')
        ->and(campaignTreeNames($page))->toBe(['US popunder', '  banner-1', '    Trust Your Taste', '  banner-2']);

    $page->assertNoJavaScriptErrors();
});
