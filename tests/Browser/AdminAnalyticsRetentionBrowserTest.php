<?php

use App\Models\ProjectSettings;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * Analytics → Retention in a real browser: the tab at each width, the
 * cohorts and both tables scrolling inside their cards and never the page;
 * a row of the sources table cutting the cohorts — kept in the address,
 * the row sunken and its chip on — and a second click showing every account
 * again; a chip doing the same.
 */
beforeEach(function () {
    ProjectSettings::factory()->create();
    actingAs(User::factory()->admin()->create());

    // Thirty accounts from Reddit old enough for every window, twelve back on day 1 and six of them on day 7 too; five of today from Google.
    $reddit = analyticsVisit('reddit', ['source_key' => 'reddit:organic', 'device' => 'desktop']);
    $google = analyticsVisit('google', ['source_key' => 'google:organic', 'device' => 'mobile']);
    $old = now()->utc()->subDays(45)->setTime(10, 0)->toDateTimeString();

    foreach (range(1, 30) as $n) {
        retentionAccount($old, match (true) {
            $n <= 6 => [1, 7],
            $n <= 12 => [1],
            default => [],
        }, ['credited_visit_id' => $reddit->id]);
    }

    foreach (range(1, 5) as $n) {
        // Today's: in a week still going, whatever the day, so never measured yet.
        retentionAccount(now()->utc()->startOfDay()->toDateTimeString(), [], ['credited_visit_id' => $google->id]);
    }
});

/** Opens an Analytics → Retention address and waits for the cohorts it reads once it is in the browser (wire:init). */
function visitAnalyticsRetention(string $url, int $width = 1440): mixed
{
    $page = visitAdminEditor($url, $width);
    waitForScript($page, "document.querySelector('[data-testid=\"analytics-retention-cohorts\"]') !== null", true, 10.0);

    return $page;
}

/** The cohorts card's title, as the browser draws it. */
function retentionCohortsTitle(mixed $page): ?string
{
    return $page->script("document.querySelector('[data-testid=\"analytics-retention-cohorts\"] .rg-admin-card__title')?.textContent.trim()");
}

it('draws the tab at every width, the cohorts and both tables scrolling inside their cards, never the page', function (int $width) {
    $page = visitAnalyticsRetention('/admin/analytics?tab=retention', $width);

    expect($page->script("[...document.querySelectorAll('.rg-admin-analytics__tabs .rg-admin-tab')].map((tab) => tab.textContent.trim())"))->toBe(['Overview', 'Site engagement', 'Campaigns', 'Tracking links', 'Landings', 'Games', 'Experiments', 'Retention', 'Geography', 'Journeys'])
        ->and($page->script("[...document.querySelectorAll('[data-testid=\"analytics-retention-card\"] .rg-admin-analytics__kpi-label')].map((label) => label.textContent.trim())"))->toBe(['New users', 'Returning users', 'Day 1 retention', 'Day 7 retention', 'Day 30 retention'])
        ->and($page->script("[...document.querySelectorAll('[data-testid=\"analytics-retention-card\"] .rg-admin-analytics__kpi-value')].map((value) => value.textContent.trim()).slice(2)"))->toBe(['40%', '20%', '0%'])
        ->and(retentionCohortsTitle($page))->toBe('Registration cohorts · All new users')
        ->and($page->script("[...document.querySelectorAll('[data-testid=\"analytics-retention-chip\"]')].map((chip) => chip.textContent.trim())"))->toBe(['All new users', 'Google · organic_search', 'Reddit · organic_social'])
        ->and($page->script(<<<'JS'
            (() => {
                const scrolls = [...document.querySelectorAll('[data-testid^="analytics-retention-"] .rg-admin-table__scroll')]

                return {
                    pageOverflows: document.documentElement.scrollWidth > window.innerWidth,
                    scrolls: scrolls.length,
                    tablesInCards: scrolls.every((scroll) => (scroll.scrollWidth > scroll.clientWidth) === (scroll.querySelector('[role="table"]').offsetWidth > scroll.clientWidth)),
                    shaded: getComputedStyle(document.querySelector('.rg-admin-analytics__heat--heat')).backgroundColor !== 'rgba(0, 0, 0, 0)',
                }
            })()
        JS))->toBe(['pageOverflows' => false, 'scrolls' => 3, 'tablesInCards' => true, 'shaded' => true]);

    $page->assertNoJavaScriptErrors();
})->with([1440, 1280, 1024, 390]);

it('cuts the cohorts by a source’s row, kept in the address, and shows every account again on a second click', function () {
    $page = visitAnalyticsRetention('/admin/analytics?tab=retention');

    $page->click('[data-testid="analytics-retention-sources"] [data-dim="google:organic"] .rg-admin-analytics__retention-name');
    waitForScript($page, "new URLSearchParams(location.search).get('dim')", 'google:organic', 10.0);
    waitForScript($page, "document.querySelector('[data-testid=\"analytics-retention-cohorts\"] .rg-admin-card__title')?.textContent.trim()", 'Registration cohorts · Google · organic_search', 10.0);

    expect($page->script("document.querySelector('[data-testid=\"analytics-retention-sources\"] [data-dim=\"google:organic\"]').classList.contains('rg-admin-table__row--selected')"))->toBeTrue()
        ->and($page->script("document.querySelector('[data-testid=\"analytics-retention-chip\"][aria-checked=\"true\"]')?.textContent.trim()"))->toBe('Google · organic_search');

    $page->click('[data-testid="analytics-retention-sources"] [data-dim="google:organic"] .rg-admin-analytics__retention-name');
    waitForScript($page, "new URLSearchParams(location.search).get('dim')", null, 10.0);
    waitForScript($page, "document.querySelector('[data-testid=\"analytics-retention-cohorts\"] .rg-admin-card__title')?.textContent.trim()", 'Registration cohorts · All new users', 10.0);

    $page->click('[data-testid="analytics-retention-chip"]:has-text("Reddit")');
    waitForScript($page, "new URLSearchParams(location.search).get('dim')", 'reddit:organic', 10.0);
    waitForScript($page, "document.querySelector('[data-testid=\"analytics-retention-cohorts\"] .rg-admin-card__title')?.textContent.trim()", 'Registration cohorts · Reddit · organic_social', 10.0);

    expect($page->script("document.querySelector('[data-testid=\"analytics-retention-sources\"] [data-dim=\"reddit:organic\"]').classList.contains('rg-admin-table__row--selected')"))->toBeTrue();

    $page->assertNoJavaScriptErrors();
});
