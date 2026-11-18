<?php

use App\Actions\Traffic\SaveTrafficSourceAction;
use App\Enums\TrafficBlockLevel;
use App\Models\ProjectSettings;
use App\Models\TrafficBlock;
use App\Models\TrafficCampaign;
use App\Models\TrafficSource;
use App\Models\TrafficVisit;
use App\Models\User;
use App\Support\Traffic\Presets\SourceDefaults;
use Carbon\CarbonImmutable;

use function Pest\Laravel\actingAs;

/**
 * Growth → Traffic sources in a real browser, its days chosen as a booking
 * site has them, with nothing to confirm: a custom range picked with two
 * clicks in the period picker's own panel — whole on the screen, the days up
 * to the pointer lit while only the start is chosen, the report reading the
 * range the moment its end is picked; a fixed price's days in one calendar,
 * given an end by keys, left with none by No end and by closing after a
 * start, each saved as chosen; a campaign's price from the day clicked; a
 * source's blocked sites opened from its editor at the list itself; and a
 * source switched between the general block rule and its own thresholds.
 */
beforeEach(function () {
    ProjectSettings::factory()->create();
    actingAs(User::factory()->admin()->create());
});

/** The visible day buttons' state, by day: whether each is an end of the range, between its ends, or the end the pointer would make. */
function trafficRangeDays(mixed $page, string $calendar): array
{
    return $page->script(<<<JS
        Object.fromEntries([...document.querySelectorAll('{$calendar} [data-day]')].map((day) => [
            new Date(Number(day.dataset.day)).toISOString().slice(0, 10),
            ['edge', 'between', 'reach'].filter((state) => day.classList.contains('rg-admin-date-range__day--' + state)).join(' '),
        ]))
    JS);
}

it('picks a custom range in the period picker’s own calendar with two clicks, the days up to the pointer lit, and reads the report for it', function (int $width) {
    $today = CarbonImmutable::now('UTC')->startOfDay();
    $first = $today->subDays(9);
    $last = $today->subDay();
    // One visit in the range, one before it: the report reads one once the range is taken.
    TrafficVisit::factory()->create(['arrived_at' => $today->subDays(5)->setTime(10, 0), 'network_day' => $today->subDays(5)->toDateString(), 'opened_at' => $today->subDays(5)->setTime(10, 0)]);
    TrafficVisit::factory()->create(['arrived_at' => $today->subDays(20)->setTime(10, 0), 'network_day' => $today->subDays(20)->toDateString(), 'opened_at' => $today->subDays(20)->setTime(10, 0)]);
    $page = visitAdminEditor('/admin/traffic-sources', $width);
    $panel = '#rg-admin-traffic-period-panel';
    $visits = "document.querySelector('.rg-admin-traffic__tile-value')?.textContent.trim()";
    $day = fn (CarbonImmutable $day): string => dateRangeDayButton($page, $panel, $day);

    waitForScript($page, $visits, '2', 10.0);
    $page->click('#rg-admin-traffic-period');
    waitForScript($page, "getComputedStyle(document.querySelector('{$panel}')).display !== 'none'");

    // One drop-down: the presets beside one calendar — two months on a wide screen, one on a phone — whole on the screen, the page no wider.
    expect($page->script(<<<JS
        (() => {
            const panel = document.querySelector('{$panel}')
            const box = panel.getBoundingClientRect()
            const points = [[box.left + 4, box.top + 4], [box.right - 4, box.top + 4], [box.left + 4, box.bottom - 4], [box.right - 4, box.bottom - 4]]

            return {
                whole: box.left >= 0 && box.top >= 0 && box.right <= window.innerWidth && box.bottom <= window.innerHeight,
                onTop: points.every(([x, y]) => panel.contains(document.elementFromPoint(x, y))),
                overflow: document.documentElement.scrollWidth > window.innerWidth,
                months: panel.querySelectorAll('.rg-admin-date-range__month').length,
                fields: panel.querySelectorAll('.rg-admin-date-time__trigger, input').length,
                buttons: [...panel.querySelectorAll('button')].map((button) => button.textContent.trim()).filter((text) => ['Apply', 'Cancel', 'Clear'].includes(text)),
                status: panel.querySelector('.rg-admin-date-range__status').textContent,
            }
        })()
    JS))->toBe(['whole' => true, 'onTop' => true, 'overflow' => false, 'months' => $width >= 768 ? 2 : 1, 'fields' => 0, 'buttons' => [], 'status' => $today->subDays(29)->format('d M').' — '.$today->format('d M Y').' · 30 days']);

    // The first click starts the range and drops the end; the panel stays open.
    $page->click($day($first));
    waitForScript($page, "document.querySelector('{$panel} .rg-admin-date-range__status').textContent", 'From '.$first->format('d M Y').' · pick the end date');

    // While only the start is chosen, the days from it to the pointer are lit, and no other.
    $page->hover($day($last));
    waitForScript($page, "document.querySelector('{$day($last)}').classList.contains('rg-admin-date-range__day--reach')");
    foreach (trafficRangeDays($page, $panel) as $date => $state) {
        expect($state)->toBe(match (true) {
            $date === $first->toDateString() => 'edge',
            $date > $first->toDateString() && $date < $last->toDateString() => 'between',
            $date === $last->toDateString() => 'reach',
            default => '',
        }, $date);
    }

    // The second click takes the range: the address, the trigger and the figures follow, and the panel closes.
    $page->click($day($last));
    waitForScript($page, "(() => { const query = new URLSearchParams(location.search); return query.get('from') + ' ' + query.get('to') })()", $first->toDateString().' '.$last->toDateString());
    waitForScript($page, "document.querySelector('#rg-admin-traffic-period .rg-admin-period__name')?.textContent.trim()", 'Custom range');
    waitForScript($page, "document.querySelector('#rg-admin-traffic-period .rg-admin-period__dates')?.textContent.trim()", $first->format('d M').' — '.$last->format('d M Y'));
    waitForScript($page, $visits, '1', 10.0);
    waitForScript($page, "getComputedStyle(document.querySelector('{$panel}')).display", 'none');

    // Opened again, the calendar shows the range taken: its ends filled, the days between lightly.
    $page->click('#rg-admin-traffic-period');
    waitForScript($page, "getComputedStyle(document.querySelector('{$panel}')).display !== 'none'");
    expect($page->script("document.querySelector('{$day($last)}').className"))->toContain('rg-admin-date-range__day--edge')
        ->and($page->script("document.querySelector('{$panel} .rg-admin-date-range__status').textContent"))->toBe($first->format('d M').' — '.$last->format('d M Y').' · 9 days');

    $page->assertNoJavaScriptErrors();
})->with([1440, 390]);

it('chooses a fixed price’s days in one calendar — an end by keys, then none by No end or by closing after a new start — and saves each as chosen', function () {
    $today = CarbonImmutable::now('UTC')->startOfDay();
    // A banner saved as the editor saves one, so the editor saves it again as it stands.
    $source = app(SaveTrafficSourceAction::class)->handle(auth()->user(), null, [...SourceDefaults::forPlacement()['source'], 'name' => 'Cat blog sidebar', 'link_key' => 'cat', 'fixed_amount' => '120', 'fixed_period' => 'month', 'fixed_from' => $today->toDateString()], []);
    $page = visitAdminEditor('/admin/traffic-sources?tab=sources&edit='.$source->id);
    // The trigger carries the field's id.
    $field = '#rg-admin-source-dates';
    $dialog = '#rg-admin-source-dates-dialog';
    $shown = "document.querySelector('{$field} .rg-admin-date-time__value').textContent.trim()";
    $open = "getComputedStyle(document.querySelector('{$dialog}')).display !== 'none'";
    $wire = "window.Livewire.all().find((component) => component.name.endsWith('TrafficSourcesPage')).\$wire";
    // Saves the draft and waits for the editor to read the source back.
    $save = function () use ($page, $wire): void {
        $version = $page->script("{$wire}.get('draftLockVersion')");
        $page->click('#rg-admin-traffic-source button:has-text("Save"):visible');
        waitForScript($page, "{$wire}.get('draftLockVersion')", $version + 1, 10.0);
    };

    // The editor's panes switch once Alpine has drawn them.
    waitForScript($page, "document.querySelector('#rg-admin-traffic-source [role=\"tab\"][aria-selected=\"true\"]') !== null");
    $page->click('#rg-admin-traffic-source [role="tab"]:has-text("Spend")');
    waitForScript($page, "document.querySelector('{$field}').offsetParent !== null");
    waitForScript($page, $shown, 'From '.$today->format('d M Y').' · no end');

    // The trigger opens the calendar by keys on the range's start, whole inside the drawer's body and the window.
    $page->keys($field, 'Enter');
    waitForScript($page, $open);
    waitForScript($page, 'document.activeElement?.getAttribute("aria-label")', $today->format('l j F Y').', today, the start');
    expect($page->script(<<<JS
        (() => {
            const dialog = document.querySelector('{$dialog}')
            const box = dialog.getBoundingClientRect()
            const body = dialog.closest('.rg-admin-drawer__body')?.getBoundingClientRect() ?? { left: 0, top: 0, right: window.innerWidth, bottom: window.innerHeight }

            return box.left >= body.left && box.top >= body.top && box.right <= body.right && box.bottom <= body.bottom && box.right <= window.innerWidth && box.bottom <= window.innerHeight
        })()
    JS))->toBeTrue()
        ->and($page->script("document.querySelector('{$dialog} .rg-admin-date-range__status').textContent"))->toBe('Pick the end date, or close for no end');

    // Two days on, Enter ends the range: taken at once, the calendar closed.
    $page->keys(':focus', 'ArrowRight');
    $page->keys(':focus', 'ArrowRight');
    waitForScript($page, 'document.activeElement?.getAttribute("aria-label")', $today->addDays(2)->format('l j F Y'));
    $page->keys(':focus', 'Enter');
    waitForScript($page, $shown, $today->format('d M').' — '.$today->addDays(2)->format('d M Y'));
    waitForScript($page, $open, false);
    $save();
    expect($source->fresh()->only(['fixed_from', 'fixed_to']))->toBe(['fixed_from' => $today->toDateString(), 'fixed_to' => $today->addDays(2)->toDateString()]);

    // No end takes the end off and nothing else.
    $page->click($field);
    waitForScript($page, $open);
    $page->click($dialog.' .rg-admin-date-range__no-end');
    waitForScript($page, $shown, 'From '.$today->format('d M Y').' · no end');
    waitForScript($page, $open, false);
    $save();
    expect($source->fresh()->only(['fixed_from', 'fixed_to']))->toBe(['fixed_from' => $today->toDateString(), 'fixed_to' => null]);

    // A day before the start starts the range again, taken the moment it is clicked; closing keeps it with no end.
    $earlier = $today->subDays(3);
    $page->click($field);
    waitForScript($page, $open);
    $page->click(dateRangeDayButton($page, $dialog, $earlier));
    waitForScript($page, $shown, 'From '.$earlier->format('d M Y').' · no end');
    expect($page->script("document.querySelector('{$dialog} .rg-admin-date-range__status').textContent"))->toBe('Pick the end date, or close for no end');
    $page->keys(':focus', 'Escape');
    waitForScript($page, $open, false);
    $save();
    expect($source->fresh()->only(['fixed_from', 'fixed_to']))->toBe(['fixed_from' => $earlier->toDateString(), 'fixed_to' => null]);

    $page->assertNoJavaScriptErrors();
});

it('keeps the focus on a day when the fixed price’s calendar turns the month, so Escape closes the calendar and not the editor', function () {
    // A price from the 1st of a month whose next month starts later in the week: once the month turns, the start's cell is blank and its button gone.
    $start = CarbonImmutable::now('UTC')->startOfMonth();
    while ($start->addMonth()->dayOfWeekIso <= $start->dayOfWeekIso) {
        $start = $start->addMonth();
    }
    $source = app(SaveTrafficSourceAction::class)->handle(auth()->user(), null, [...SourceDefaults::forPlacement()['source'], 'name' => 'Cat blog sidebar', 'link_key' => 'cat', 'fixed_amount' => '120', 'fixed_period' => 'month', 'fixed_from' => $start->toDateString()], []);
    $page = visitAdminEditor('/admin/traffic-sources?tab=sources&edit='.$source->id);
    $field = '#rg-admin-source-dates';
    $dialog = '#rg-admin-source-dates-dialog';
    $open = "getComputedStyle(document.querySelector('{$dialog}')).display !== 'none'";

    waitForScript($page, "document.querySelector('#rg-admin-traffic-source [role=\"tab\"][aria-selected=\"true\"]') !== null");
    $page->click('#rg-admin-traffic-source [role="tab"]:has-text("Spend")');
    waitForScript($page, "document.querySelector('{$field}').offsetParent !== null");
    $page->click($field);
    waitForScript($page, $open);
    waitForScript($page, 'document.activeElement?.getAttribute("aria-label")', $start->format('l j F Y').', the start');

    // The month turned by the pointer: the focus moves to the day Tab reaches in the months now shown, not to the drawer's Close.
    $page->click($dialog.' [aria-label="Next month"]:visible');
    waitForScript($page, "document.activeElement?.matches('{$dialog} .rg-admin-date-time__day') === true");

    // Escape closes the calendar back to its trigger; the editor stays open, its source in the address.
    $page->keys(':focus', 'Escape');
    waitForScript($page, $open, false);
    expect($page->script('document.activeElement?.id'))->toBe('rg-admin-source-dates')
        ->and($page->script("document.querySelector('#rg-admin-traffic-source')?.offsetParent !== null"))->toBeTrue()
        ->and($page->script("new URLSearchParams(location.search).get('edit')"))->toBe((string) $source->id);

    $page->assertNoJavaScriptErrors();
});

it('takes a campaign’s price day the moment it is clicked, with nothing to confirm', function () {
    $today = CarbonImmutable::now('UTC')->startOfDay();
    $campaign = TrafficCampaign::factory()->create(['source_id' => TrafficSource::factory()->create(['name' => 'PopAds main'])->id, 'name' => 'US popunder']);
    $page = visitAdminEditor('/admin/traffic-sources?tab=campaigns&camp='.$campaign->id);
    // The trigger carries the field's id.
    $field = '#rg-admin-campaign-from';
    $dialog = '#rg-admin-campaign-from-dialog';
    $wire = "window.Livewire.all().find((component) => component.name.endsWith('TrafficSourcesPage')).\$wire";

    $page->click('.rg-admin-drawer label:has-text("This campaign’s price × visits") input');
    waitForScript($page, "document.querySelector('{$field}') !== null");
    $page->click($field);
    waitForScript($page, "getComputedStyle(document.querySelector('{$dialog}')).display !== 'none'");
    expect($page->script("[...document.querySelectorAll('{$dialog} button')].map((button) => button.textContent.trim()).filter((text) => ['Apply', 'Cancel', 'Clear'].includes(text))"))->toBe([]);

    // Yesterday from its quick picks, then another day of its month by a click: each taken at once, the dialog closed.
    $yesterday = $today->subDay();
    $picked = $yesterday->setDay($yesterday->day === 15 ? 16 : 15);
    $page->click($dialog.' .rg-admin-date-time__preset:has-text("Yesterday")');
    waitForScript($page, "{$wire}.get('campaignDraft.price_from')", $yesterday->toDateString());
    waitForScript($page, "getComputedStyle(document.querySelector('{$dialog}')).display", 'none');

    $page->click($field);
    waitForScript($page, "getComputedStyle(document.querySelector('{$dialog}')).display !== 'none'");
    $page->click($dialog.' [aria-label^="'.$picked->format('l j F Y').'"]');
    waitForScript($page, "document.querySelector('{$field} .rg-admin-date-time__value').textContent.trim()", $picked->format('D d M Y'));
    waitForScript($page, "{$wire}.get('campaignDraft.price_from')", $picked->toDateString());
    waitForScript($page, "getComputedStyle(document.querySelector('{$dialog}')).display", 'none');

    $page->assertNoJavaScriptErrors();
});

it('opens a source’s blocked sites from its Blocking pane at the list itself, once the report is drawn', function () {
    $today = CarbonImmutable::now('UTC')->startOfDay();
    $source = TrafficSource::factory()->create(['name' => 'PopAds main', 'block_level' => TrafficBlockLevel::Site]);
    TrafficVisit::factory()->count(3)->create(['source_id' => $source->id, 'site_ref' => '40123', 'arrived_at' => $today->subDay()->setTime(10, 0), 'network_day' => $today->subDay()->toDateString()]);
    TrafficBlock::factory()->create(['source_id' => $source->id, 'level' => TrafficBlockLevel::Site, 'ref' => '40123']);
    $page = visitAdminEditor('/admin/traffic-sources?tab=sources&edit='.$source->id, 1440, 700);

    waitForScript($page, "document.querySelector('#rg-admin-traffic-source [role=\"tab\"][aria-selected=\"true\"]') !== null");
    $page->click('#rg-admin-traffic-source [role="tab"]:has-text("Blocking")');
    waitForScript($page, "document.querySelector('[data-testid=\"traffic-source-blocked-sites\"]').offsetParent !== null");
    // On this server's origin, whole: the browser plugin drops the anchor of an address given as a path.
    $link = $page->script("(() => { const link = new URL(document.querySelector('[data-testid=\"traffic-source-blocked-sites\"]').href); return location.origin + link.pathname + link.search + link.hash })()");

    expect($link)->toEndWith('#traffic-performance');

    // The list comes after the page's first paint, and the page scrolls to it then; the address keeps no anchor to jump to again.
    $list = $page->navigate($link);
    waitForScript($list, "document.querySelector('#traffic-performance') !== null", true, 10.0);
    waitForScript($list, "(() => { const box = document.querySelector('#traffic-performance').getBoundingClientRect(); return box.top < window.innerHeight && box.bottom > 0 && window.scrollY > 0 })()");
    waitForScript($list, 'location.hash', '');
    expect($list->script("document.querySelector('#traffic-performance').textContent"))->toContain('site 40123')->toContain('Blocked');

    $list->assertNoJavaScriptErrors();
});

it('switches a source between the general block rule and its own thresholds on its Blocking pane, saving each with the source', function () {
    $source = TrafficSource::factory()->create(['name' => 'PopAds main', 'block_level' => TrafficBlockLevel::Site]);
    $page = visitAdminEditor('/admin/traffic-sources?tab=sources&edit='.$source->id);
    $pane = '[data-testid="traffic-source-thresholds"]';
    $bots = $pane.' input[aria-label="Clicks before bots show"]';
    $wire = "window.Livewire.all().find((component) => component.name.endsWith('TrafficSourcesPage')).\$wire";
    // Saves the draft and waits for the editor to read the source back.
    $save = function () use ($page, $wire): void {
        $version = $page->script("{$wire}.get('draftLockVersion')");
        $page->click('#rg-admin-traffic-source button:has-text("Save"):visible');
        waitForScript($page, "{$wire}.get('draftLockVersion')", $version + 1, 10.0);
    };

    waitForScript($page, "document.querySelector('#rg-admin-traffic-source [role=\"tab\"][aria-selected=\"true\"]') !== null");
    $page->click('#rg-admin-traffic-source [role="tab"]:has-text("Blocking")');
    waitForScript($page, "document.querySelector('{$pane}').offsetParent !== null");

    // The general rule: its values read out at the body's leading, no field to type in.
    expect($page->script("[...document.querySelectorAll('{$pane} input')].filter((input) => input.offsetParent !== null).length"))->toBe(0)
        ->and($page->script("document.querySelector('{$pane} .rg-admin-traffic__rule-line').innerText"))->toBe('Likely bots: at least 100 clicks, and fewer opened the page than 50% and than 50% of the source’s own average, or than 5% whatever the average — 0 for no floor.');

    // Its own thresholds: each field empty, the general value shown in it.
    $page->click($pane.' [role="radio"]:has-text("Own thresholds for this source")');
    waitForScript($page, "document.querySelector('{$bots}').offsetParent !== null");
    expect($page->script("document.querySelector('{$bots}').placeholder"))->toBe('100');
    $page->type($bots, '45');
    $save();

    expect($source->fresh()->block_bot_clicks)->toBe(45);
    waitForScript($page, "document.querySelector('{$bots}').value", '45');

    // Back on the general rule its own are emptied in the draft, and come back on returning before saving.
    $page->click($pane.' [role="radio"]:has-text("General rule")');
    waitForScript($page, "{$wire}.get('draft.source.block_bot_clicks')", null);
    expect($page->script("getComputedStyle(document.querySelector('{$pane} .rg-admin-traffic__rule-line')).lineHeight"))->toBe('22px');
    $page->click($pane.' [role="radio"]:has-text("Own thresholds for this source")');
    waitForScript($page, "document.querySelector('{$bots}').value", '45');

    // Back on the general rule and saved, they are emptied.
    $page->click($pane.' [role="radio"]:has-text("General rule")');
    waitForScript($page, "{$wire}.get('draft.source.block_bot_clicks')", null);
    $save();

    expect($source->fresh()->block_bot_clicks)->toBeNull();
    $page->assertNoJavaScriptErrors();
});
