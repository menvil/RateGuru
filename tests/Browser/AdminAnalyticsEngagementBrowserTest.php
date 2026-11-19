<?php

use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * Analytics → Site engagement in a real browser: the tab at each width, the
 * nine tiles with their pills, the funnel and the two sources compared, the
 * posts scrolling inside their card and never the page; a source picked for
 * a side kept in the address with the sentence that sums them up, and the
 * same source on both sides asking for two.
 */
beforeEach(function () {
    ProjectSettings::factory()->create();
    actingAs(User::factory()->admin()->create());
    recordTraffic(site: true);

    $recent = now()->subDays(2);
    $post = Post::factory()->published()->create(['title' => 'Glacier Blue Water']);
    $visit = fn (string $browser, array $attributes): mixed => analyticsVisit($browser, ['network_day' => $recent->toDateString(), 'arrived_at' => $recent->toDateTimeString(), 'device' => 'desktop', ...$attributes]);

    // Reddit's people open the post and vote more often than Google's; a few come by email.
    foreach (range(1, 32) as $n) {
        analyticsVisitPost($visit('reddit-'.$n, ['source_key' => 'reddit:organic', 'votes' => $n <= 12 ? 1 : 0]), $post->id, ['views' => 2, 'voted' => $n <= 12]);
    }

    foreach (range(1, 30) as $n) {
        analyticsVisitPost($visit('google-'.$n, ['source_key' => 'google:organic', 'votes' => $n <= 3 ? 1 : 0]), $post->id, ['voted' => $n <= 3]);
    }

    foreach (range(1, 3) as $n) {
        $visit('email-'.$n, ['source_key' => 'email:email']);
    }
});

/** Opens an Analytics → Site engagement address and waits for the figures it reads once it is in the browser (wire:init). */
function visitAnalyticsEngagement(string $url, int $width = 1440): mixed
{
    $page = visitAdminEditor($url, $width);
    waitForScript($page, "document.querySelector('[data-testid=\"analytics-engagement-cards\"]') !== null", true, 10.0);

    return $page;
}

/** The sentence under the two sources compared, as the browser draws it. */
function engagementAnswer(mixed $page): ?string
{
    return $page->script("document.querySelector('[data-testid=\"analytics-engagement-answer\"]')?.textContent.trim()");
}

it('draws the tab at every width, the posts scrolling inside their card, never the page', function (int $width) {
    $page = visitAnalyticsEngagement('/admin/analytics?tab=engagement', $width);

    expect($page->script("[...document.querySelectorAll('.rg-admin-analytics__tabs .rg-admin-tab')].map((tab) => tab.textContent.trim())"))->toBe(['Overview', 'Site engagement', 'Campaigns', 'Tracking links', 'Landings', 'Games', 'Experiments', 'Retention', 'Geography', 'Journeys'])
        ->and($page->script("[...document.querySelectorAll('[data-testid=\"analytics-engagement-card\"] .rg-admin-analytics__unit')].map((pill) => pill.textContent.trim())"))->toBe(['Visitors', 'Visitors', 'Actions', 'Visitors', 'Actions', 'Actions', 'Actions', 'Accounts', 'Accounts'])
        ->and($page->script("[...document.querySelectorAll('[data-testid=\"analytics-engagement-post\"] .rg-admin-analytics__post-title')].map((title) => title.textContent.trim())"))->toBe(['Glacier Blue Water'])
        ->and(engagementAnswer($page))->toBe('Reddit visitors vote more often: 37.5% of post viewers voted, against 10.0% from Google.')
        ->and($page->script(<<<'JS'
            (() => {
                const scroll = document.querySelector('[data-testid="analytics-engagement-content"] .rg-admin-table__scroll')
                const grid = scroll.querySelector('[role="table"]')
                const compare = document.querySelector('[data-testid="analytics-engagement-compare"]')
                const versus = compare.querySelector('.rg-admin-analytics__versus')

                return {
                    pageOverflows: document.documentElement.scrollWidth > window.innerWidth,
                    postsInCard: (scroll.scrollWidth > scroll.clientWidth) === (grid.offsetWidth > scroll.clientWidth),
                    compareFits: versus.scrollWidth <= compare.clientWidth,
                }
            })()
        JS))->toBe(['pageOverflows' => false, 'postsInCard' => true, 'compareFits' => true]);

    $page->assertNoJavaScriptErrors();
})->with([1440, 1280, 1024, 390]);

it('keeps a source picked for a side in the address, and asks for two different ones when both are the same', function () {
    $page = visitAnalyticsEngagement('/admin/analytics?tab=engagement');

    expect($page->script("[...document.querySelectorAll('[aria-label=\"Source B\"] [role=\"radio\"][aria-checked=\"true\"]')].map((option) => option.textContent.trim())"))->toBe(['Google']);

    $page->click('[aria-label="Source B"] [role="radio"]:has-text("Email")');
    waitForScript($page, "new URLSearchParams(location.search).get('b')", 'email:email', 10.0);
    waitForScript($page, "document.querySelector('[data-testid=\"analytics-engagement-answer\"]')?.textContent.trim()", 'Too few visitors from Reddit or Email to compare how they vote or sign up yet.', 10.0);

    $page->click('[aria-label="Source A"] [role="radio"]:has-text("Email")');
    waitForScript($page, "new URLSearchParams(location.search).get('a')", 'email:email', 10.0);
    waitForScript($page, "document.querySelector('[data-testid=\"analytics-engagement-answer\"]')?.textContent.trim()", 'Pick two different sources to compare.', 10.0);

    expect($page->script("[...document.querySelectorAll('[role=\"radio\"][aria-checked=\"true\"]')].filter((option) => option.closest('[data-testid=\"analytics-engagement-compare\"]')).map((option) => option.textContent.trim())"))->toBe(['Email', 'Email']);

    $page->assertNoJavaScriptErrors();
});
