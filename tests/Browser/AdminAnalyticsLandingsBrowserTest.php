<?php

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\GameVersion;
use App\Models\ProjectSettings;
use App\Models\User;
use App\Support\Analytics\LandingRoute;

use function Pest\Laravel\actingAs;

/**
 * Analytics → Landings in a real browser: the tab at each width, the
 * landings and the comparison each scrolling inside its card — the
 * comparison's metric column held in place — and never the page; a game's
 * row opening the game on the Games tab, of the same days and device; and
 * Experiment results opening the comparison on the Experiments tab.
 */
beforeEach(function () {
    ProjectSettings::factory()->create();
    actingAs(User::factory()->admin()->create());

    $this->game = Game::factory()->status(GameStatus::Live)->create(['title' => 'Trust Your Taste', 'slug' => 'trust-your-taste']);
    $this->period = comparisonPeriod($this->game, [7 => 80, 8 => 20], now()->subDays(6)->toDateTimeString());
    $recent = now()->subDays(2);

    foreach ($this->period->versions as $version) {
        $version->flow->forceFill(['name' => $version->number === 7 ? 'Result First' : 'Email Before Result'])->save();

        foreach (range(1, 3) as $n) {
            periodVisitor($this->period, $version, ['completed' => $n <= 2]);
        }
    }

    $first = GameVersion::query()->where('game_id', $this->game->id)->where('number', 7)->sole();

    foreach (range(1, 4) as $n) {
        analyticsVisit('player-'.$n, ['network_day' => $recent->toDateString(), 'arrived_at' => $recent->toDateTimeString(), 'landing_key' => LandingRoute::key('games.play', $this->game->id, $first->id), 'game_id' => $this->game->id, 'version_id' => $first->id, 'device' => 'desktop']);
    }

    foreach (range(1, 2) as $n) {
        analyticsVisit('reader-'.$n, ['network_day' => $recent->toDateString(), 'arrived_at' => $recent->toDateTimeString(), 'landing_key' => 'feed', 'device' => 'desktop']);
    }
});

/** Opens an Analytics → Landings address and waits for the landings it reads once it is in the browser (wire:init). */
function visitAnalyticsLandings(string $url, int $width = 1440): mixed
{
    $page = visitAdminEditor($url, $width);
    waitForScript($page, "document.querySelector('[data-testid=\"analytics-landings\"]') !== null", true, 10.0);

    return $page;
}

it('draws the tab at every width, the landings and the comparison scrolling inside their cards with the metric held in place, never the page', function (int $width) {
    $page = visitAnalyticsLandings('/admin/analytics?tab=landings', $width);

    expect($page->script("[...document.querySelectorAll('.rg-admin-analytics__tabs .rg-admin-tab')].map((tab) => tab.textContent.trim())"))->toBe(['Overview', 'Landings', 'Games', 'Experiments', 'Geography'])
        ->and($page->script("[...document.querySelectorAll('[data-testid=\"analytics-landing-row\"] .rg-admin-analytics__landing-path')].map((path) => path.textContent.trim())"))->toBe(['/games/trust-your-taste', '/'])
        ->and($page->script("document.querySelector('[data-testid=\"analytics-landings-compare\"] .rg-admin-card__title')?.textContent.trim()"))->toBe('Result First · V7 vs Email Before Result · V8')
        ->and($page->script(<<<'JS'
            (() => {
                const fits = (selector) => {
                    const scroll = document.querySelector(selector + ' .rg-admin-table__scroll')
                    const grid = scroll.querySelector('[role="table"]')

                    return (scroll.scrollWidth > scroll.clientWidth) === (grid.offsetWidth > scroll.clientWidth)
                }
                const scroll = document.querySelector('[data-testid="analytics-landings-compare"] .rg-admin-table__scroll')
                const metric = scroll.querySelector('[data-testid="analytics-landings-compare-row"] .rg-admin-analytics__compare-metric')
                scroll.scrollLeft = 120

                return {
                    pageOverflows: document.documentElement.scrollWidth > window.innerWidth,
                    landingsInCard: fits('[data-testid="analytics-landings"]'),
                    comparisonInCard: fits('[data-testid="analytics-landings-compare"]'),
                    metricHeld: Math.abs(metric.getBoundingClientRect().left - scroll.getBoundingClientRect().left) <= 1,
                }
            })()
        JS))->toBe(['pageOverflows' => false, 'landingsInCard' => true, 'comparisonInCard' => true, 'metricHeld' => true]);

    $page->assertNoJavaScriptErrors();
})->with([1440, 1280, 1024, 390]);

it('opens a game’s row on the Games tab, of the same days and device', function () {
    $page = visitAnalyticsLandings('/admin/analytics?tab=landings&period=7d&device=desktop&source=direct');

    $page->click('.rg-admin-analytics__landing-row--link .rg-admin-analytics__landing-experience');
    waitForScript($page, "document.querySelector('[data-testid=\"game-analytics-filters\"]') !== null", true, 10.0);

    // The source and campaign stay behind: they don't apply there.
    expect($page->script('Object.fromEntries(new URLSearchParams(location.search))'))->toEqual(['tab' => 'games', 'game' => (string) $this->game->id, 'period' => '7d', 'device' => 'desktop'])
        ->and($page->script("document.querySelector('[data-testid=\"game-analytics-games\"] [aria-checked=\"true\"]')?.textContent.trim()"))->toBe('Trust Your Taste');

    $page->assertNoJavaScriptErrors();
});

it('opens the comparison’s results on the Experiments tab', function () {
    $page = visitAnalyticsLandings('/admin/analytics?tab=landings');

    $page->click('[data-testid="analytics-landings-results"]');
    waitForScript($page, "document.querySelector('[data-testid=\"analytics-experiment\"]') !== null", true, 10.0);

    expect($page->script('Object.fromEntries(new URLSearchParams(location.search))'))->toEqual(['tab' => 'experiments', 'exp' => (string) $this->period->id])
        ->and($page->script("document.querySelector('.rg-admin-experiments__choice[aria-pressed=\"true\"]')?.dataset.period"))->toBe((string) $this->period->id);

    $page->assertNoJavaScriptErrors();
});
