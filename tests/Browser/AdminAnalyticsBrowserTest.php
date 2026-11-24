<?php

use App\Models\ProjectSettings;
use App\Models\User;
use Carbon\CarbonImmutable;

use function Pest\Laravel\actingAs;

/**
 * Growth → Analytics in a real browser: the shell and Overview at each width,
 * the sources table scrolling inside its card and never the page; the strip
 * of the tabs built so far; another period, with the top bar's days moving
 * along; a custom range picked with two clicks in the period picker's own
 * calendar, never longer than Analytics reads at a time; a device, a source
 * from its menu with its chip and the chip's ×, a source row filtering the
 * page and a second click clearing it; the sources sorted by a rate; the
 * period last chosen, which an address naming none opens on; and every
 * report page's presets, All time covering the days since the first visit.
 */
beforeEach(function () {
    ProjectSettings::factory()->create();
    actingAs(User::factory()->admin()->create());

    $recent = now()->subDays(2);
    $earlier = now()->subDays(20);

    // Enough people on each source for its rates to show.
    foreach (range(1, 31) as $n) {
        analyticsVisit('reddit-'.$n, ['network_day' => $recent->toDateString(), 'arrived_at' => $recent->toDateTimeString(), 'source_key' => 'reddit:organic', 'started_at' => $n <= 2 ? $recent->toDateTimeString() : null]);
    }

    foreach (range(1, 30) as $n) {
        analyticsVisit('google-'.$n, ['network_day' => $recent->toDateString(), 'arrived_at' => $recent->toDateTimeString(), 'source_key' => 'google:organic', 'device' => 'desktop', 'started_at' => $recent->toDateTimeString()]);
    }

    analyticsVisit('earlier', ['network_day' => $earlier->toDateString(), 'arrived_at' => $earlier->toDateTimeString(), 'source_key' => 'reddit:organic']);
});

/** Opens an Analytics address and waits for the figures it reads once it is in the browser (wire:init). */
function visitAnalytics(string $url, int $width = 1440): mixed
{
    $page = visitAdminEditor($url, $width);
    waitForScript($page, "document.querySelector('[data-testid=\"analytics-kpis\"]') !== null", true, 10.0);

    return $page;
}

/** What the page shows: the first tile's figure, the chip, the sources in order, the address and the top bar's days. */
function analyticsScreen(mixed $page): array
{
    return $page->script(<<<'JS'
        (() => ({
            visitors: document.querySelector('.rg-admin-analytics__kpi-value')?.textContent.trim(),
            chip: document.querySelector('[data-testid="analytics-source-chip"] .rg-admin-analytics__chip-text')?.textContent.trim() ?? null,
            sources: [...document.querySelectorAll('.rg-admin-analytics__source-row .rg-admin-analytics__source-name')].map((name) => name.textContent.trim()),
            search: location.search,
            range: document.querySelector('[data-testid="analytics-range"]')?.textContent.trim(),
        }))()
    JS);
}

it('draws the shell and Overview at every width, never wider than the screen', function (int $width) {
    $page = visitAnalytics('/admin/analytics', $width);

    expect($page->script("[...document.querySelectorAll('.rg-admin-analytics__tabs .rg-admin-tab')].map((tab) => tab.textContent.trim())"))->toBe(['Overview', 'Site engagement', 'Campaigns', 'Tracking links', 'Landings', 'Games', 'Experiments', 'Retention', 'Geography', 'Journeys'])
        ->and($page->script(<<<'JS'
            (() => {
                const scroll = document.querySelector('[data-testid="analytics-sources"] .rg-admin-table__scroll')
                const grid = scroll.querySelector('[role="table"]')

                return {
                    pageOverflows: document.documentElement.scrollWidth > window.innerWidth,
                    scrollsWhenNarrower: (scroll.scrollWidth > scroll.clientWidth) === (grid.offsetWidth > scroll.clientWidth),
                }
            })()
        JS))->toBe(['pageOverflows' => false, 'scrollsWhenNarrower' => true]);

    $page->assertNoJavaScriptErrors();
})->with([1440, 1280, 1024, 390]);

it('reads out the busiest day, then the count of the column pointed at, arrowed to or tapped, on a scale of round values', function (int $width) {
    $page = visitAnalytics('/admin/analytics?period=7d', $width);
    $readout = fn (): string => trim((string) $page->script("document.querySelector('[data-testid=\"analytics-chart-readout\"]').textContent"));
    $busiest = now()->subDays(2)->format('D d M Y');

    expect($readout())->toBe('Busiest: '.$busiest.' · 61 visitors')
        ->and($page->script("[...document.querySelectorAll('.rg-admin-analytics__axis-value')].map((value) => value.textContent.trim())"))->toBe(['20', '40', '60', '80'])
        ->and($page->script('document.documentElement.scrollWidth > window.innerWidth'))->toBeFalse();

    // The busiest column is the fifth of seven: pointed at on a desktop, tapped on a phone.
    if ($width > 600) {
        $page->hover('.rg-admin-analytics__bar:nth-child(5)');
    } else {
        $page->click('.rg-admin-analytics__bar:nth-child(5)');
    }

    waitForScript($page, "document.querySelector('[data-testid=\"analytics-chart-readout\"]').textContent.trim() === ".json_encode($busiest.' · 61 visitors'));

    // From the keyboard: Home, then one column on; Escape goes back to the busiest.
    $page->script("document.querySelector('.rg-admin-analytics__field').focus()");
    $page->keys(':focus', 'Home');
    $page->keys(':focus', 'ArrowRight');

    expect($readout())->toBe(now()->subDays(5)->format('D d M Y').' · 0 visitors')
        ->and($page->script("document.querySelectorAll('.rg-admin-analytics__bar--chosen').length"))->toBe(1);

    $page->keys(':focus', 'Escape');

    expect($readout())->toBe('Busiest: '.$busiest.' · 61 visitors');
    $page->assertNoJavaScriptErrors();
})->with([1440, 390]);

it('changes the period, the device and the source, with the chip and a source row, and sorts the sources', function () {
    $page = visitAnalytics('/admin/analytics');
    // The first day carries its year only when the range crosses into another, as the top bar writes it.
    $start = now()->subDays(6);
    $last7 = $start->format($start->year === now()->year ? 'd M' : 'd M Y').' — '.now()->format('d M Y');

    // The period is always in the address, the default too: a link to it opens Last 30 days whatever its reader last chose.
    expect(analyticsScreen($page))->toMatchArray(['visitors' => '62', 'chip' => null, 'sources' => ['Reddit', 'Google'], 'search' => '?period=30d']);

    // Another period: the figures and the top bar's days move with it.
    $page->click('#rg-admin-analytics-period');
    $page->click('.rg-admin-period__preset:has-text("Last 7 days")');
    waitForScript($page, "document.querySelector('.rg-admin-analytics__kpi-value')?.textContent.trim()", '61', 10.0);
    expect(analyticsScreen($page))->toMatchArray(['search' => '?period=7d', 'range' => $last7]);

    // A device.
    $page->click('#rg-admin-analytics-device');
    $page->click('#rg-admin-analytics-device-menu [role=menuitemradio]:has-text("Desktop")');
    waitForScript($page, "document.querySelector('.rg-admin-analytics__kpi-value')?.textContent.trim()", '30', 10.0);
    expect(analyticsScreen($page))->toMatchArray(['sources' => ['Google'], 'search' => '?period=7d&device=desktop']);

    $page->click('#rg-admin-analytics-device');
    $page->click('#rg-admin-analytics-device-menu [role=menuitemradio]:has-text("All devices")');
    waitForScript($page, "document.querySelector('.rg-admin-analytics__kpi-value')?.textContent.trim()", '61', 10.0);

    // A source from its menu, named by the chip, and the chip's × taking it off.
    $page->click('#rg-admin-analytics-source');
    $page->click('#rg-admin-analytics-source-menu [role=menuitemradio]:has-text("Reddit · organic_social")');
    waitForScript($page, "document.querySelector('[data-testid=\"analytics-source-chip\"]')?.textContent.trim().replace(/\\s+/g, ' ')", 'Source: Reddit · organic_social', 10.0);
    expect(analyticsScreen($page))->toMatchArray(['visitors' => '31', 'search' => '?period=7d&source=reddit%3Aorganic']);

    $page->click('[data-testid="analytics-source-chip"] button');
    waitForScript($page, "document.querySelector('[data-testid=\"analytics-source-chip\"]') === null", true, 10.0);
    expect(analyticsScreen($page))->toMatchArray(['visitors' => '61', 'search' => '?period=7d']);

    // A source row filters the whole page, and a second click clears it.
    $page->click('.rg-admin-analytics__source-row .rg-admin-analytics__source-name:has-text("Google")');
    waitForScript($page, "document.querySelector('.rg-admin-analytics__kpi-value')?.textContent.trim()", '30', 10.0);
    expect(analyticsScreen($page))->toMatchArray(['chip' => 'Source: Google · organic_search'])
        ->and($page->script("document.querySelector('.rg-admin-analytics__source-row--selected .rg-admin-analytics__source-name')?.textContent.trim()"))->toBe('Google');

    $page->click('.rg-admin-analytics__source-row .rg-admin-analytics__source-name:has-text("Google")');
    waitForScript($page, "document.querySelector('.rg-admin-analytics__kpi-value')?.textContent.trim()", '61', 10.0);
    expect(analyticsScreen($page)['chip'])->toBeNull();

    // Sorted by start rate, the source whose every visitor started comes first.
    $page->click('.rg-admin-analytics__sort [role=radio]:has-text("Start rate")');
    waitForScript($page, "[...document.querySelectorAll('.rg-admin-analytics__source-row .rg-admin-analytics__source-name')].map((name) => name.textContent.trim()).join()", 'Google,Reddit', 10.0);
    expect(analyticsScreen($page)['search'])->toBe('?period=7d&sort=start');

    // Opened again from an address naming no period: the period last chosen, put back in the address, every report page's presets offered, Today first.
    $page = $page->navigate($page->script('location.origin').'/admin/analytics');
    waitForScript($page, "document.querySelector('.rg-admin-analytics__kpi-value')?.textContent.trim()", '61', 10.0);
    waitForScript($page, 'location.search', '?period=7d');
    expect(analyticsScreen($page))->toMatchArray(['range' => $last7])
        ->and($page->script("[...document.querySelectorAll('#rg-admin-analytics-period-panel .rg-admin-period__preset')].map((preset) => preset.textContent.trim())"))
        ->toBe(['Today', 'Yesterday', 'Last 7 days', 'Last 30 days', 'Last 90 days', 'This month', 'Last month', 'All time']);

    // All time: every visitor since the first visit, twenty days ago — the days beside the period's name and in the top bar saying so — in the address as any preset.
    $since = now()->subDays(20);
    $allTime = $since->format($since->year === now()->year ? 'd M' : 'd M Y').' — '.now()->format('d M Y');
    $page->click('#rg-admin-analytics-period');
    $page->click('#rg-admin-analytics-period-panel .rg-admin-period__preset:has-text("All time")');
    waitForScript($page, 'location.search', '?period=all', 10.0);
    waitForScript($page, "document.querySelector('.rg-admin-analytics__kpi-value')?.textContent.trim()", '62', 10.0);
    expect(analyticsScreen($page))->toMatchArray(['range' => $allTime])
        ->and($page->script("document.querySelector('#rg-admin-analytics-period .rg-admin-period__name').textContent.trim()"))->toBe('All time')
        ->and($page->script("document.querySelector('#rg-admin-analytics-period .rg-admin-period__dates').textContent.trim()"))->toBe($allTime);

    $page->assertNoJavaScriptErrors();
});

it('picks a custom range in the period picker’s own calendar with two clicks, no longer than Analytics reads at a time', function () {
    $today = CarbonImmutable::now('UTC')->startOfDay();
    $page = visitAnalytics('/admin/analytics');
    $panel = '#rg-admin-analytics-period-panel';
    $status = "document.querySelector('{$panel} .rg-admin-date-range__status').textContent";
    $first = $today->subDays(25);
    $last = $today->subDays(10);

    $page->click('#rg-admin-analytics-period');
    waitForScript($page, "getComputedStyle(document.querySelector('{$panel}')).display !== 'none'");

    // A start, then an end: the range is taken at once — the address, the trigger, the top bar and the figures follow, and the panel closes.
    $page->click(dateRangeDayButton($page, $panel, $first));
    waitForScript($page, $status, 'From '.$first->format('d M Y').' · pick the end date');
    $page->hover(dateRangeDayButton($page, $panel, $last));
    waitForScript($page, "document.querySelector('".dateRangeDayButton($page, $panel, $first->addDay())."').classList.contains('rg-admin-date-range__day--between')");
    $page->click(dateRangeDayButton($page, $panel, $last));
    waitForScript($page, "document.querySelector('.rg-admin-analytics__kpi-value')?.textContent.trim()", '1', 10.0);
    expect(analyticsScreen($page))->toMatchArray(['search' => '?period=30d&from='.$first->toDateString().'&to='.$last->toDateString(), 'range' => $first->format($first->year === $last->year ? 'd M' : 'd M Y').' — '.$last->format('d M Y')])
        ->and($page->script("document.querySelector('#rg-admin-analytics-period .rg-admin-period__name').textContent.trim()"))->toBe('Custom range')
        ->and($page->script("getComputedStyle(document.querySelector('{$panel}')).display"))->toBe('none');

    // From a start months back, the 93rd day can end the range and the 94th can't; the calendar says so.
    $page->click('#rg-admin-analytics-period');
    waitForScript($page, "getComputedStyle(document.querySelector('{$panel}')).display !== 'none'");
    $start = $today->subDays(150);
    $page->click(dateRangeDayButton($page, $panel, $start));
    waitForScript($page, $status, 'From '.$start->format('d M Y').' · pick the end date');
    expect($page->script("document.querySelector('".dateRangeDayButton($page, $panel, $start->addDays(92))."').disabled"))->toBeFalse()
        ->and($page->script("document.querySelector('".dateRangeDayButton($page, $panel, $start->addDays(93))."').disabled"))->toBeTrue()
        ->and($page->script("document.querySelector('{$panel} .rg-admin-date-range__limit').textContent.trim()"))->toBe('Up to 93 days at a time');

    // Escape closes the panel and keeps the range taken before.
    $page->keys(':focus', 'Escape');
    waitForScript($page, "getComputedStyle(document.querySelector('{$panel}')).display", 'none');
    expect(analyticsScreen($page)['search'])->toBe('?period=30d&from='.$first->toDateString().'&to='.$last->toDateString());

    // A start picked, then Escape from a preset, outside the calendar: the start goes with the panel, and reopened it shows the range taken.
    $taken = $first->format($first->year === $last->year ? 'd M' : 'd M Y').' — '.$last->format('d M Y').' · 16 days';
    $page->click('#rg-admin-analytics-period');
    waitForScript($page, "getComputedStyle(document.querySelector('{$panel}')).display !== 'none'");
    waitForScript($page, $status, $taken);
    $page->click(dateRangeDayButton($page, $panel, $first->addDay()));
    waitForScript($page, $status, 'From '.$first->addDay()->format('d M Y').' · pick the end date');
    $page->script("document.querySelector('{$panel} .rg-admin-period__preset').focus()");
    $page->keys(':focus', 'Escape');
    waitForScript($page, "getComputedStyle(document.querySelector('{$panel}')).display", 'none');
    $page->click('#rg-admin-analytics-period');
    waitForScript($page, "getComputedStyle(document.querySelector('{$panel}')).display !== 'none'");
    expect($page->script($status))->toBe($taken)
        ->and(analyticsScreen($page)['search'])->toBe('?period=30d&from='.$first->toDateString().'&to='.$last->toDateString());

    $page->assertNoJavaScriptErrors();
});
