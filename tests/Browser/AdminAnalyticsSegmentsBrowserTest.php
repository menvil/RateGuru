<?php

use App\Models\ProjectSettings;
use App\Models\TrafficCampaign;
use App\Models\TrafficSegment;
use App\Models\TrafficSource;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * Analytics → Campaigns → a paid source's segments in a real browser:
 * Segments filtering the page by the source; Add segment opening the drawer,
 * which says what the conditions catch as they are typed; Save putting the
 * segment on the card; Delete taking it off with Undo bringing it back; and
 * the card and the drawer at each width, never wider than the screen.
 */
beforeEach(function () {
    ProjectSettings::factory()->create();
    actingAs(User::factory()->admin()->create());

    $this->exo = TrafficSource::factory()->create(['name' => 'ExoClick tests']);
    $campaign = TrafficCampaign::factory()->create(['source_id' => $this->exo->id, 'name' => 'US popunder']);
    $recent = now()->subDays(2);
    $day = ['network_day' => $recent->toDateString(), 'arrived_at' => $recent->toDateTimeString(), 'spend' => '0.10'];

    foreach (range(1, 6) as $n) {
        analyticsClick('mobile-'.$n, $campaign, [...$day, 'site_ref' => '1182', 'device' => 'mobile']);
    }

    foreach (range(1, 4) as $n) {
        analyticsClick('desktop-'.$n, $campaign, [...$day, 'site_ref' => '2210', 'device' => 'desktop']);
    }
});

/** Opens an Analytics → Campaigns address and waits for the paid sources it reads once it is in the browser (wire:init). */
function visitAnalyticsSegments(string $url, int $width = 1440): mixed
{
    $page = visitAdminEditor($url, $width);
    waitForScript($page, "document.querySelector('[data-testid=\"analytics-paid-sources\"]') !== null", true, 10.0);

    return $page;
}

/** The segments card's rows as the browser shows them: each name. */
function segmentRowNames(mixed $page): array
{
    return $page->script("[...document.querySelectorAll('[data-testid=\"analytics-segment-row\"] .rg-admin-analytics__segment-name')].map((name) => name.textContent.trim())");
}

/** The script reading the latest toast's text. */
function segmentToastText(): string
{
    return "[...document.querySelectorAll('.rg-admin-toast .rg-admin-toast__text')].at(-1)?.textContent.trim() ?? ''";
}

it('adds a segment, previewing what it catches, saves it, deletes it and brings it back with Undo', function () {
    $page = visitAnalyticsSegments('/admin/analytics?tab=campaigns');

    $page->click('[data-testid="analytics-paid-segments"]');
    waitForScript($page, "document.querySelector('[data-testid=\"analytics-segments\"]') !== null", true, 10.0);

    expect($page->script("new URLSearchParams(location.search).get('source')"))->toBe('paid:'.$this->exo->id)
        ->and(segmentRowNames($page))->toBe(['All ExoClick tests']);

    $page->click('[data-testid="analytics-segment-add"]');
    waitForScript($page, "document.querySelector('[data-testid=\"analytics-segment-drawer\"]') !== null", true, 10.0);

    expect($page->script("new URLSearchParams(location.search).get('segment')"))->toBe('new')
        ->and($page->script("document.querySelector('[data-testid=\"analytics-segment-preview\"]').textContent.trim()"))->toBe('Fill every condition to see how many visitors it matches.');

    $page->type('#rg-admin-traffic-segment-name', 'Publisher 1182');
    $page->type('#rg-admin-traffic-segment-values-0', '1182');
    waitForScript($page, "document.querySelector('[data-testid=\"analytics-segment-preview\"]').textContent.trim().startsWith('Matches 6 visitors and 0 registrations of ExoClick tests')", true, 10.0);
    waitForScript($page, "document.querySelector('[data-testid=\"analytics-segment-save\"]').disabled", false, 10.0);

    $page->click('[data-testid="analytics-segment-save"]');
    waitForScript($page, segmentToastText(), 'Segment “Publisher 1182” saved', 10.0);
    waitForScript($page, "document.querySelector('[data-testid=\"analytics-segment-drawer\"]') === null", true, 10.0);

    expect(segmentRowNames($page))->toBe(['All ExoClick tests', 'Publisher 1182', 'Not in any segment'])
        ->and($page->script("document.querySelector('[data-testid=\"analytics-segment-chip\"]').textContent.trim()"))->toBe('Site is 1182')
        ->and($page->script("new URLSearchParams(location.search).get('segment')"))->toBeNull();

    $page->click('[data-testid="analytics-segment-edit"]');
    waitForScript($page, "document.querySelector('#rg-admin-traffic-segment-name')?.value", 'Publisher 1182', 10.0);

    $page->click('[data-testid="analytics-segment-delete"]');
    waitForScript($page, segmentToastText(), 'Segment “Publisher 1182” deleted', 10.0);
    waitForScript($page, "document.querySelectorAll('[data-testid=\"analytics-segment-row\"]').length", 1, 10.0);

    expect(TrafficSegment::query()->withTrashed()->sole()->trashed())->toBeTrue();

    $page->click('.rg-admin-toast .rg-admin-toast__action');
    waitForScript($page, "document.querySelectorAll('[data-testid=\"analytics-segment-row\"]').length", 3, 10.0);

    expect(segmentRowNames($page))->toBe(['All ExoClick tests', 'Publisher 1182', 'Not in any segment'])
        ->and(TrafficSegment::query()->sole()->name)->toBe('Publisher 1182');

    $page->assertNoJavaScriptErrors();
});

it('draws the segments and a segment’s drawer at every width, never wider than the screen', function (int $width) {
    TrafficSegment::factory()->create(['source_id' => $this->exo->id, 'name' => 'Desktop', 'rules' => [['parameter' => 'device', 'operator' => 'in', 'values' => ['desktop']]]]);

    $page = visitAnalyticsSegments('/admin/analytics?tab=campaigns&source=paid:'.$this->exo->id.'&segment=new', $width);
    waitForScript($page, "document.querySelector('[data-testid=\"analytics-segment-drawer\"] .rg-admin-drawer') !== null", true, 10.0);

    expect(segmentRowNames($page))->toBe(['All ExoClick tests', 'Desktop', 'Not in any segment'])
        ->and($page->script(<<<'JS'
            (() => {
                const drawer = document.querySelector('[data-testid="analytics-segment-drawer"] .rg-admin-drawer').getBoundingClientRect()
                const scroll = document.querySelector('[data-testid="analytics-segments"] .rg-admin-table__scroll')
                const grid = scroll.querySelector('[role="table"]')

                return {
                    pageOverflows: document.documentElement.scrollWidth > window.innerWidth,
                    drawerFits: drawer.width <= window.innerWidth && Math.round(drawer.right) === window.innerWidth,
                    tableInCard: (scroll.scrollWidth > scroll.clientWidth) === (grid.offsetWidth > scroll.clientWidth),
                }
            })()
        JS))->toBe(['pageOverflows' => false, 'drawerFits' => true, 'tableInCard' => true]);

    $page->assertNoJavaScriptErrors();
})->with([1440, 1280, 1024, 390]);
