<?php

use App\Models\ProjectSettings;
use App\Models\User;
use App\Support\Analytics\JourneyTimeline;
use Carbon\CarbonImmutable;

use function Pest\Laravel\actingAs;

/**
 * Analytics → Journeys in a real browser: the tab at each width, the first
 * example's journey beside Attribution and Identity — under it where both
 * don't fit — and never wider than the page; an ID typed into the search
 * showing its journey, kept in the address; an example picked; and
 * Overview's example journey opening the tab.
 */
beforeEach(function () {
    ProjectSettings::factory()->create();
    actingAs(User::factory()->admin()->create());

    $this->member = journeyAccount(CarbonImmutable::now('UTC')->subDays(10)->startOfDay());
    $this->player = journeyPlayer(CarbonImmutable::now('UTC')->subDays(6)->setTime(12, 3));
});

/** Opens an Analytics → Journeys address and waits for the journey or the word it reads once it is in the browser (wire:init). */
function visitAnalyticsJourneys(string $url, int $width = 1440): mixed
{
    $page = visitAdminEditor($url, $width);
    waitForScript($page, "document.querySelector('[data-testid=\"analytics-journey\"], [data-testid=\"analytics-journey-missing\"], [data-testid=\"analytics-journey-matches\"]') !== null", true, 10.0);

    return $page;
}

/** The journey's name, as the browser draws it. */
function journeyLabel(mixed $page): ?string
{
    return $page->script("document.querySelector('[data-testid=\"analytics-journey-label\"]')?.textContent.trim() ?? null");
}

it('draws the first example’s journey at every width, beside or above Attribution and Identity, never wider than the page', function (int $width) {
    $page = visitAnalyticsJourneys('/admin/analytics?tab=journeys', $width);
    $member = 'user_'.$this->member['user']->id;

    expect($page->script("[...document.querySelectorAll('.rg-admin-analytics__tabs .rg-admin-tab')].map((tab) => tab.textContent.trim())"))->toBe(['Overview', 'Site engagement', 'Campaigns', 'Tracking links', 'Landings', 'Games', 'Experiments', 'Retention', 'Geography', 'Journeys'])
        ->and($page->script("[...document.querySelectorAll('[data-testid=\"analytics-journey-example\"]')].map((chip) => chip.textContent.trim() + (chip.getAttribute('aria-pressed') === 'true' ? ' *' : ''))"))->toBe([$member.' *', JourneyTimeline::visitorId($this->player['hash'])])
        ->and(journeyLabel($page))->toBe(JourneyTimeline::visitorId($this->member['hash']).' · '.$member)
        ->and($page->script("document.querySelectorAll('[data-testid=\"analytics-journey-step\"]').length"))->toBe(15)
        ->and($page->script("[...document.querySelectorAll('[data-testid=\"analytics-journey-touch\"] dt')].map((label) => label.textContent.trim())"))->toBe(['First touch', 'Conversion touch', 'Last non-direct touch', 'Registration credited to'])
        ->and($page->script(<<<'JS'
            (() => {
                const path = document.querySelector('[data-testid="analytics-journey-path"]').getBoundingClientRect()
                const side = document.querySelector('[data-testid="analytics-journey-attribution"]').getBoundingClientRect()

                return {
                    pageOverflows: document.documentElement.scrollWidth > window.innerWidth,
                    beside: side.left >= path.right,
                    under: side.top >= path.bottom,
                }
            })()
        JS))->toBe(['pageOverflows' => false, 'beside' => $width >= 1280, 'under' => $width < 1280]);

    $page->assertNoJavaScriptErrors();
})->with([1440, 1280, 1024, 390]);

it('shows the journey of an ID typed into the search, keeps it in the address, and of an example picked', function () {
    $page = visitAnalyticsJourneys('/admin/analytics?tab=journeys');
    $player = JourneyTimeline::visitorId($this->player['hash']);

    $page->type('#rg-admin-analytics-journey-search', strtoupper($player));
    waitForScript($page, "new URLSearchParams(location.search).get('who')", $player, 10.0);
    waitForScript($page, "document.querySelector('[data-testid=\"analytics-journey-label\"]')?.textContent.trim()", $player.' · not registered', 10.0);

    expect($page->script("[...document.querySelectorAll('[data-testid=\"analytics-journey-touch\"] dd:first-of-type')].map((value) => value.textContent.trim())"))->toBe(['Direct / Unknown', 'Shared results · referral', 'None yet'])
        ->and($page->script("document.querySelector('[data-testid=\"analytics-journey-example\"][aria-pressed=\"true\"]')?.textContent.trim()"))->toBe($player);

    $page->click('[data-testid="analytics-journey-example"]:has-text("user_")');
    waitForScript($page, "new URLSearchParams(location.search).get('who')", 'user_'.$this->member['user']->id, 10.0);
    waitForScript($page, "document.querySelector('[data-testid=\"analytics-journey-label\"]')?.textContent.trim()", JourneyTimeline::visitorId($this->member['hash']).' · user_'.$this->member['user']->id, 10.0);

    $page->assertNoJavaScriptErrors();
});

it('opens the journey of one of Overview’s people from its funnel', function () {
    $page = visitAdminEditor('/admin/analytics');
    waitForScript($page, "document.querySelector('[data-testid=\"analytics-example-journey\"]') !== null", true, 10.0);

    $page->click('[data-testid="analytics-example-journey"]');
    waitForScript($page, "new URLSearchParams(location.search).get('tab')", 'journeys', 10.0);
    waitForScript($page, "document.querySelector('[data-testid=\"analytics-journey-label\"]')?.textContent.trim()", JourneyTimeline::visitorId($this->member['hash']).' · user_'.$this->member['user']->id, 10.0);

    expect($page->script("new URLSearchParams(location.search).get('who')"))->toBe('user_'.$this->member['user']->id);

    $page->assertNoJavaScriptErrors();
});
