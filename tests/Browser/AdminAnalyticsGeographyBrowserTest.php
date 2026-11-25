<?php

use App\Models\ProjectSettings;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * Analytics → Geography in a real browser: the tab at each width, the
 * countries and cities scrolling inside their card and never the page, the
 * country the most people came from open with its cities; a click on a
 * country opening it and closing the one open, a second click closing it,
 * each in the address; and the sorting by a rate of new visitors.
 */
beforeEach(function () {
    ProjectSettings::factory()->create();
    actingAs(User::factory()->admin()->create());

    $recent = now()->subDays(2);
    $visit = fn (string $browser, array $attributes): mixed => analyticsVisit($browser, ['network_day' => $recent->toDateString(), 'arrived_at' => $recent->toDateTimeString(), 'device' => 'desktop', ...$attributes]);

    // Germany: Berlin with enough people to name, the rest of the country together; the United States with more accounts.
    foreach (range(1, 24) as $n) {
        $visit('berlin-'.$n, ['country' => 'DE', 'city' => 'Berlin']);
    }

    foreach (range(1, 8) as $n) {
        $visit('munich-'.$n, ['country' => 'DE', 'city' => 'Munich']);
    }

    foreach (range(1, 30) as $n) {
        $visit('austin-'.$n, ['country' => 'US', 'city' => 'Austin', ...($n <= 6 ? ['signup_at' => $recent->toDateTimeString(), 'account_at' => $recent->toDateTimeString()] : [])]);
    }

    $visit('nowhere', ['country' => null]);
});

/** Opens an Analytics → Geography address and waits for the countries it reads once it is in the browser (wire:init). */
function visitAnalyticsGeography(string $url, int $width = 1440): mixed
{
    $page = visitAdminEditor($url, $width);
    waitForScript($page, "document.querySelector('[data-testid=\"analytics-geo\"]') !== null", true, 10.0);

    return $page;
}

/** The table's rows as the browser draws them: the name of each, cities after their country. */
function geographyRowNames(mixed $page): array
{
    return $page->script("[...document.querySelectorAll('[data-testid=\"analytics-geo-row\"] .rg-admin-analytics__geo-name')].map((name) => name.textContent.trim())");
}

it('draws the tab at every width, the countries and cities scrolling inside their card, never the page', function (int $width) {
    $page = visitAnalyticsGeography('/admin/analytics?tab=geo&open=DE', $width);

    expect($page->script("[...document.querySelectorAll('.rg-admin-analytics__tabs .rg-admin-tab')].map((tab) => tab.textContent.trim())"))->toBe(['Overview', 'Site engagement', 'Campaigns', 'Tracking links', 'Landings', 'Games', 'Experiments', 'Retention', 'Geography', 'Journeys'])
        ->and(geographyRowNames($page))->toBe(['Germany', 'Berlin', 'Other cities', 'United States', 'Location unknown'])
        ->and($page->script("document.querySelector('[data-testid=\"analytics-geo-banner\"]')?.textContent.includes('Cities with fewer than 20 visitors are grouped into “Other cities”')"))->toBeTrue()
        ->and($page->script(<<<'JS'
            (() => {
                const scroll = document.querySelector('[data-testid="analytics-geo"] .rg-admin-table__scroll')
                const grid = scroll.querySelector('[role="table"]')
                const city = document.querySelector('.rg-admin-analytics__geo-row--city .rg-admin-analytics__geo-name').getBoundingClientRect()
                const country = document.querySelector('.rg-admin-analytics__geo-row--country .rg-admin-analytics__geo-name').getBoundingClientRect()

                return {
                    pageOverflows: document.documentElement.scrollWidth > window.innerWidth,
                    tableInCard: (scroll.scrollWidth > scroll.clientWidth) === (grid.offsetWidth > scroll.clientWidth),
                    cityIndented: city.left > country.left,
                }
            })()
        JS))->toBe(['pageOverflows' => false, 'tableInCard' => true, 'cityIndented' => true]);

    $page->assertNoJavaScriptErrors();
})->with([1440, 1280, 1024, 390]);

it('opens no country by itself, a country’s cities on a click, closing the one open, and closes it on another, each in the address', function () {
    $page = visitAnalyticsGeography('/admin/analytics?tab=geo');

    expect(geographyRowNames($page))->toBe(['Germany', 'United States', 'Location unknown'])
        ->and($page->script("[...document.querySelectorAll('.rg-admin-analytics__geo-row--country .rg-admin-analytics__geo-name')].map((name) => name.getAttribute('aria-expanded'))"))->toBe(['false', 'false']);

    $page->click('.rg-admin-analytics__geo-row--country:nth-child(2) .rg-admin-analytics__geo-share');
    waitForScript($page, "new URLSearchParams(location.search).get('open')", 'DE', 10.0);
    waitForScript($page, "document.querySelectorAll('.rg-admin-analytics__geo-row--inner').length > 0", true, 10.0);

    expect(geographyRowNames($page))->toBe(['Germany', 'Berlin', 'Other cities', 'United States', 'Location unknown']);

    $page->click('.rg-admin-analytics__geo-row--country:nth-child(5) .rg-admin-analytics__geo-share');
    waitForScript($page, "new URLSearchParams(location.search).get('open')", 'US', 10.0);
    waitForScript($page, "document.querySelectorAll('.rg-admin-analytics__geo-row--inner').length", 1, 10.0);

    expect(geographyRowNames($page))->toBe(['Germany', 'United States', 'Austin', 'Location unknown'])
        ->and($page->script("[...document.querySelectorAll('.rg-admin-analytics__geo-row--country .rg-admin-analytics__geo-name')].map((name) => name.getAttribute('aria-expanded'))"))->toBe(['false', 'true']);

    $page->click('.rg-admin-analytics__geo-row--country:nth-child(3) .rg-admin-analytics__geo-name');
    waitForScript($page, "new URLSearchParams(location.search).has('open')", false, 10.0);
    waitForScript($page, "document.querySelectorAll('.rg-admin-analytics__geo-row--inner').length", 0, 10.0);

    expect(geographyRowNames($page))->toBe(['Germany', 'United States', 'Location unknown']);

    $page->assertNoJavaScriptErrors();
});

it('sorts by a rate of new visitors, kept in the address', function () {
    $page = visitAnalyticsGeography('/admin/analytics?tab=geo');

    $page->click('[data-testid="analytics-geo"] .rg-admin-analytics__sort [role=radio]:has-text("Registration rate")');
    waitForScript($page, "new URLSearchParams(location.search).get('gsort')", 'registration', 10.0);
    waitForScript($page, "document.querySelector('.rg-admin-analytics__geo-row--country .rg-admin-analytics__geo-name')?.textContent.trim()", 'United States', 10.0);

    expect(geographyRowNames($page))->toBe(['United States', 'Germany', 'Location unknown'])
        ->and($page->script("document.querySelector('[data-testid=\"analytics-geo\"] [role=\"radio\"][aria-checked=\"true\"]')?.textContent.trim()"))->toBe('Registration rate');

    $page->assertNoJavaScriptErrors();
});
