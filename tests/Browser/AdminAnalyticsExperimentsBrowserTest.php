<?php

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\ProjectSettings;
use App\Models\TrafficCampaign;
use App\Models\TrafficSource;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * Analytics → Experiments in a real browser: the tab at each width, its
 * table scrolling inside its card with the metric column held in place while
 * the versions scroll under it, and never the page; each chip's whole name
 * on a phone, a split's scope included; and another comparison chosen from
 * the chooser, shown and put in the address.
 */
beforeEach(function () {
    ProjectSettings::factory()->create();
    actingAs(User::factory()->admin()->create());

    $game = Game::factory()->status(GameStatus::Live)->create(['title' => 'Trust Your Taste']);
    $this->ended = comparisonPeriod($game, [7 => 50, 8 => 50], now()->subDays(30)->toDateTimeString(), now()->subDays(10)->toDateTimeString());
    $this->current = comparisonPeriod($game, [7 => 40, 8 => 30, 9 => 30], now()->subDays(10)->toDateTimeString());

    foreach ([$this->ended, $this->current] as $period) {
        foreach ($period->versions as $version) {
            foreach (range(1, 3) as $ignored) {
                periodVisitor($period, $version, ['completed' => true]);
            }
        }
    }
});

/** Opens an Analytics → Experiments address and waits for the comparison it reads once it is in the browser (wire:init). */
function visitAnalyticsExperiments(string $url, int $width = 1440): mixed
{
    $page = visitAdminEditor($url, $width);
    waitForScript($page, "document.querySelector('[data-testid=\"analytics-experiment\"]') !== null", true, 10.0);

    return $page;
}

it('draws the tab at every width, the table scrolling inside its card with the metric column held in place, never the page', function (int $width) {
    $page = visitAnalyticsExperiments('/admin/analytics?tab=experiments', $width);

    expect($page->script("[...document.querySelectorAll('.rg-admin-analytics__tabs .rg-admin-tab')].map((tab) => tab.textContent.trim())"))->toBe(['Overview', 'Site engagement', 'Campaigns', 'Tracking links', 'Landings', 'Games', 'Experiments', 'Retention', 'Geography', 'Journeys'])
        ->and($page->script("document.querySelector('.rg-admin-experiments__name')?.textContent.trim()"))->toBe('V7 vs V8 vs V9')
        ->and($page->script(<<<'JS'
            (() => {
                const scroll = document.querySelector('[data-testid="analytics-experiment-table"] .rg-admin-table__scroll')
                const grid = scroll.querySelector('[role="table"]')
                const pinned = grid.querySelector('.rg-admin-experiments__compare-row--primary .rg-admin-experiments__pinned')
                const narrower = grid.offsetWidth > scroll.clientWidth
                scroll.scrollLeft = 200
                const held = Math.abs(pinned.getBoundingClientRect().left - scroll.getBoundingClientRect().left) <= 1

                return {
                    pageOverflows: document.documentElement.scrollWidth > window.innerWidth,
                    scrollsWhenNarrower: (scroll.scrollWidth > scroll.clientWidth) === narrower,
                    metricHeld: held,
                }
            })()
        JS))->toBe(['pageOverflows' => false, 'scrollsWhenNarrower' => true, 'metricHeld' => true]);

    $page->assertNoJavaScriptErrors();
})->with([1440, 1280, 1024, 390]);

it('shows each chip’s whole name on a phone, a split’s source and campaign included', function () {
    $source = TrafficSource::factory()->create(['name' => 'PopAds main']);
    $campaign = TrafficCampaign::factory()->create(['source_id' => $source->id, 'external_id' => '881377', 'name' => null]);
    $this->current->forceFill(['source_id' => $source->id, 'campaign_id' => $campaign->id])->save();

    $page = visitAnalyticsExperiments('/admin/analytics?tab=experiments', 390);

    // Wrapped, never cut: a phone has no hover to read a cut name by.
    expect($page->script("[...document.querySelectorAll('.rg-admin-experiments__choice-name')].map((name) => name.scrollWidth <= name.clientWidth)"))->toBe([true, true])
        ->and($page->script("document.querySelector('.rg-admin-experiments__choice[data-period=\"{$this->current->id}\"] .rg-admin-experiments__choice-name').textContent"))->toContain('PopAds main · Campaign 881377')
        ->and($page->script('document.documentElement.scrollWidth > window.innerWidth'))->toBeFalse();

    $page->assertNoJavaScriptErrors();
});

it('shows another comparison chosen from the chooser, and puts it in the address', function () {
    $page = visitAnalyticsExperiments('/admin/analytics?tab=experiments');

    expect($page->script("[...document.querySelectorAll('.rg-admin-experiments__choice')].map((choice) => choice.dataset.period + ':' + choice.getAttribute('aria-pressed'))"))
        ->toBe([$this->current->id.':true', $this->ended->id.':false']);

    $page->click('.rg-admin-experiments__choice[data-period="'.$this->ended->id.'"]');
    waitForScript($page, "document.querySelector('.rg-admin-experiments__name')?.textContent.trim()", 'V7 vs V8', 10.0);

    expect($page->script("document.querySelector('.rg-admin-experiments__choice[aria-pressed=\"true\"]')?.dataset.period"))->toBe((string) $this->ended->id)
        ->and($page->script('location.search'))->toBe('?tab=experiments&exp='.$this->ended->id)
        ->and($page->script("document.querySelector('[data-testid=\"analytics-experiment-verdict\"]')?.dataset.kind"))->toBe('ended');

    // Back to Overview, the comparison leaves the address with its tab.
    $page->click('.rg-admin-analytics__tabs .rg-admin-tab:has-text("Overview")');
    waitForScript($page, 'location.search', '', 10.0);

    $page->assertNoJavaScriptErrors();
});
