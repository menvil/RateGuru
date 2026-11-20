<?php

use App\Actions\Traffic\SaveTrafficSourceAction;
use App\Enums\TrafficBlockLevel;
use App\Models\Game;
use App\Models\ProjectSettings;
use App\Models\TrafficBlock;
use App\Models\TrafficCampaign;
use App\Models\TrafficSource;
use App\Models\TrafficVisit;
use App\Models\User;
use App\Support\Analytics\JourneyTimeline;
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
 * source's blocked sites opened from its editor at the list itself; copies
 * said to be made only once the clipboard took them; a source switched
 * between the general block rule and its own thresholds; a click's
 * visitor in Logs, a link that opens its journey, the page no wider for it;
 * a new visitor's worth under a cent whole in its columns on Value;
 * and on the way here under the tiles, with the impressions a network billed
 * in Sites & zones beside each site's visits, at a phone's width as at a
 * desktop's.
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

    // One drop-down: every report page's presets beside one calendar — two months on a wide screen, one on a phone — whole on the screen, the page no wider.
    expect($page->script(<<<JS
        (() => {
            const panel = document.querySelector('{$panel}')
            const box = panel.getBoundingClientRect()
            const points = [[box.left + 4, box.top + 4], [box.right - 4, box.top + 4], [box.left + 4, box.bottom - 4], [box.right - 4, box.bottom - 4]]

            return {
                whole: box.left >= 0 && box.top >= 0 && box.right <= window.innerWidth && box.bottom <= window.innerHeight,
                onTop: points.every(([x, y]) => panel.contains(document.elementFromPoint(x, y))),
                overflow: document.documentElement.scrollWidth > window.innerWidth,
                presets: [...panel.querySelectorAll('.rg-admin-period__preset')].map((preset) => preset.textContent.trim()),
                months: panel.querySelectorAll('.rg-admin-date-range__month').length,
                fields: panel.querySelectorAll('.rg-admin-date-time__trigger, input').length,
                buttons: [...panel.querySelectorAll('button')].map((button) => button.textContent.trim()).filter((text) => ['Apply', 'Cancel', 'Clear'].includes(text)),
                status: panel.querySelector('.rg-admin-date-range__status').textContent,
            }
        })()
    JS))->toBe([
        'whole' => true, 'onTop' => true, 'overflow' => false,
        'presets' => ['Today', 'Yesterday', 'Last 7 days', 'Last 30 days', 'Last 90 days', 'This month', 'Last month', 'All time'],
        'months' => $width >= 768 ? 2 : 1, 'fields' => 0, 'buttons' => [], 'status' => $today->subDays(29)->format('d M').' — '.$today->format('d M Y').' · 30 days',
    ]);

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
    // The 1st of this month is today on the 1st: the calendar says so before the start.
    waitForScript($page, 'document.activeElement?.getAttribute("aria-label")', $start->format('l j F Y').($start->isSameDay(CarbonImmutable::now('UTC')) ? ', today' : '').', the start');

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

it('says the ticked sites’ IDs were copied only when the clipboard took them', function () {
    $today = CarbonImmutable::now('UTC')->startOfDay();
    $source = TrafficSource::factory()->create(['name' => 'PopAds main', 'block_level' => TrafficBlockLevel::Site]);
    TrafficVisit::factory()->count(3)->create(['source_id' => $source->id, 'site_ref' => '40123', 'arrived_at' => $today->subDay()->setTime(10, 0), 'network_day' => $today->subDay()->toDateString()]);
    $page = visitAdminEditor('/admin/traffic-sources?view=sites');
    $tick = 'input[aria-label^="Tick "][aria-label$=" 40123"]';
    $copy = "[...document.querySelectorAll('[data-testid=\"traffic-selection\"] .rg-admin-button')].find((button) => /Copy IDs|Copied/.test(button.textContent))";

    waitForScript($page, "document.querySelector('{$tick}') !== null", true, 10.0);
    $page->click($tick);
    waitForScript($page, "document.querySelector('[data-testid=\"traffic-selection\"]') !== null");

    // Refused: the button never says Copied, and the toast says nothing was copied.
    $page->script("(() => { navigator.clipboard.writeText = () => Promise.reject(new DOMException('Denied', 'NotAllowedError')) })()");
    $page->script("{$copy}.click()");
    waitForScript($page, "document.querySelector('.rg-admin-toast')?.textContent.includes('nothing was copied') === true");
    expect($page->script("{$copy}.textContent.trim()"))->toBe('Copy IDs');

    // Taken: Copied.
    $page->script('(() => { navigator.clipboard.writeText = () => Promise.resolve() })()');
    $page->script("{$copy}.click()");
    waitForScript($page, "{$copy}.textContent.trim()", 'Copied');

    $page->assertNoJavaScriptErrors();
});

it('says a network’s campaign link was copied only when the clipboard took it', function () {
    TrafficSource::factory()->create(['name' => 'PopAds main']);
    Game::factory()->create();
    $page = visitAdminEditor('/admin/traffic-sources?tab=campaigns');
    $copy = '[data-testid="traffic-get-link"] .rg-admin-button';

    waitForScript($page, "document.querySelector('[data-testid=\"traffic-link\"]') !== null", true, 10.0);

    // Refused: the toast says nothing was copied, never that it was.
    $page->script("(() => { navigator.clipboard.writeText = () => Promise.reject(new DOMException('Denied', 'NotAllowedError')) })()");
    $page->click($copy);
    waitForScript($page, "document.querySelector('.rg-admin-toast')?.textContent.includes('nothing was copied') === true");
    expect($page->script("document.body.textContent.includes('Link copied')"))->toBeFalse();

    // Taken: the link as the page shows it, and Link copied.
    $page->script('(() => { navigator.clipboard.writeText = (text) => { window.copiedLink = text; return Promise.resolve() } })()');
    $page->click($copy);
    waitForScript($page, "document.body.textContent.includes('Link copied')");
    expect($page->script('window.copiedLink'))->toBe($page->script("document.querySelector('[data-testid=\"traffic-link\"]').textContent.trim()"));

    $page->assertNoJavaScriptErrors();
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

it('names a click’s visitor in Logs by a link that opens its journey in Analytics, its spend whole in its column, the page no wider at any width', function (int $width) {
    $visit = TrafficVisit::factory()->create(['visitor_hash' => hash('sha256', 'log-visitor'), 'arrived_at' => now()->subHour(), 'opened_at' => now()->subHour(), 'cost' => '0.00000012', 'spend' => '0.00000012', 'spend_basis' => 'click_cost']);
    [$id, $who] = [JourneyTimeline::visitorId($visit->visitor_hash), JourneyTimeline::visitorId($visit->visitor_hash, JourneyTimeline::LONG_DIGITS)];
    $link = '[data-testid="traffic-log-journey"]';
    $page = visitAdminEditor('/admin/traffic-sources?tab=logs&log=clicks', $width);

    waitForScript($page, "document.querySelector('{$link}')?.textContent.trim() ?? null", $id, 10.0);

    // The log is as wide as its columns and scrolls inside its panel; the page itself never does.
    expect($page->script(<<<JS
        (() => {
            const link = document.querySelector('{$link}')
            const query = new URL(link.href).searchParams
            // The amount to its eighth decimal fits the Spend column, and hovering it says where it comes from.
            const spend = document.querySelector('[data-testid="traffic-log-click-spend"]')
            const amount = document.createRange()
            amount.selectNode(spend.firstChild)

            return {
                overflow: document.documentElement.scrollWidth > window.innerWidth,
                scrolled: link.closest('.rg-admin-table__scroll') !== null,
                tab: query.get('tab'),
                who: query.get('who'),
                target: link.target,
                spend: spend.firstChild.textContent,
                hint: spend.title,
                fits: amount.getBoundingClientRect().width <= spend.getBoundingClientRect().width,
            }
        })()
    JS))->toBe(['overflow' => false, 'scrolled' => true, 'tab' => 'journeys', 'who' => $who, 'target' => '', 'spend' => '$0.00000012', 'hint' => 'Price in the click $0.00000012', 'fits' => true]);

    // Followed, it leaves Traffic sources for the visitor's journey itself, not the first example.
    $page->click($link);
    waitForScript($page, "location.pathname + ' ' + new URLSearchParams(location.search).get('who')", '/admin/analytics '.$who, 10.0);
    waitForScript($page, "document.querySelector('[data-testid=\"analytics-journey-label\"]')?.textContent.trim() ?? null", $id.' · not registered', 10.0);
    $page->assertNoJavaScriptErrors();
})->with([1440, 390]);

it('keeps a new visitor’s worth under a cent whole on Value, Mobile clear of Desktop, the page no wider at any width', function (int $width) {
    // An account at the site's $0.50: opening a game is worth $0.000759, starting it $0.00000012 — the eighth decimal, the longest an amount runs.
    finishedChanceRun(['*' => ['opened' => ['chance' => 0.001518], 'started' => ['chance' => 0.00000024]]]);
    $page = visitAdminEditor('/admin/traffic-sources?tab=value', $width);

    waitForScript($page, "document.querySelectorAll('[data-testid=\"traffic-value-visitor\"] [role=\"row\"]').length", 8, 10.0);

    // Each amount within its own cell and its row, Mobile ending before Desktop begins, every row's columns in line with the head's.
    expect($page->script(<<<'JS'
        (() => {
            const rows = [...document.querySelectorAll('[data-testid="traffic-value-visitor"] [role="row"]')]
            const box = (node) => {
                const range = document.createRange()
                range.selectNodeContents(node)

                return range.getBoundingClientRect()
            }
            const steps = rows.slice(1, 3)

            return {
                overflow: document.documentElement.scrollWidth > window.innerWidth,
                worth: steps.map((row) => [...row.children].slice(1).map((cell) => cell.textContent.trim())),
                whole: steps.every((row) => [...row.children].slice(1).every((cell) => box(cell).width <= cell.getBoundingClientRect().width + 0.5)),
                apart: steps.every((row) => box(row.children[1]).right < box(row.children[2]).left && box(row.children[2]).right <= row.getBoundingClientRect().right + 0.5),
                aligned: rows.every((row) => [1, 2].every((n) => row.children[n].getBoundingClientRect().left === rows[0].children[n].getBoundingClientRect().left)),
            }
        })()
    JS))->toBe(['overflow' => false, 'worth' => [['$0.000759', '$0.000759'], ['$0.00000012', '$0.00000012']], 'whole' => true, 'apart' => true, 'aligned' => true]);

    $page->assertNoJavaScriptErrors();
})->with([1440, 390]);

it('shows on the way here under the tiles and each site’s paid impressions in Sites & zones, the page no wider at any width', function (int $width) {
    $source = TrafficSource::factory()->create(['name' => 'PopAds main']);
    $campaign = TrafficCampaign::factory()->create(['source_id' => $source->id]);
    $yesterday = CarbonImmutable::now('UTC')->subDay()->toDateString();
    paidImpressions($source, $yesterday, 20, '0.04', ['campaign_id' => $campaign->id, 'site_ref' => '40123']);
    reachedVisits($source, $yesterday, 15, 8, 2, ['campaign_id' => $campaign->id, 'site_ref' => '40123']);
    $page = visitAdminEditor('/admin/traffic-sources', $width);

    waitForScript($page, "document.querySelector('[data-testid=\"traffic-reach\"]') !== null", true, 10.0);

    // Right under the tiles: each step with its share of the one before, then what never arrived — the card within the screen, the page no wider.
    expect($page->script(<<<'JS'
        (() => {
            const card = document.querySelector('[data-testid="traffic-reach"]')
            const box = card.getBoundingClientRect()

            return {
                overflow: document.documentElement.scrollWidth > window.innerWidth,
                within: box.left >= 0 && box.right <= window.innerWidth,
                next: document.querySelector('[data-testid="traffic-report-tiles"]').nextElementSibling === card,
                steps: [...card.querySelectorAll('[data-testid="traffic-reach-step"] .rg-admin-traffic__bar-head')].map((head) => head.innerText.replace(/\s+/g, ' ').trim()),
                lost: card.querySelector('[data-testid="traffic-reach-lost"]').textContent.trim(),
            }
        })()
    JS))->toBe([
        'overflow' => false, 'within' => true, 'next' => true,
        'steps' => ['Paid impressions (network) 20', 'Reached RateGuru 15 75%', 'Opened the page 8 53%', 'Started the game 2 25%'],
        'lost' => 'Paid, never reached: 5 (25%) · ≈ $0.01 of the network’s $0.04',
    ]);

    // Sites & zones: the impressions and the share reached before the visits, the list scrolling inside its panel.
    $page->script("[...document.querySelectorAll('.rg-admin-traffic__view')].find((view) => view.textContent.trim() === 'Sites & zones').click()");
    waitForScript($page, "document.querySelector('[data-testid=\"traffic-site-row\"]') !== null", true, 10.0);

    expect($page->script(<<<'JS'
        (() => {
            const row = document.querySelector('[data-testid="traffic-site-row"]')
            const head = row.parentElement.querySelector('.rg-admin-traffic__perf-row--head')

            return {
                overflow: document.documentElement.scrollWidth > window.innerWidth,
                scrolled: row.closest('.rg-admin-table__scroll') !== null,
                head: [...head.children].slice(1, 4).map((cell) => cell.textContent.trim()),
                cells: [...row.children].slice(1, 4).map((cell) => cell.innerText.replace(/\s+/g, ' ').trim()),
                aligned: head.children.length === row.children.length && head.children[2].getBoundingClientRect().right === row.children[2].getBoundingClientRect().right,
            }
        })()
    JS))->toBe([
        'overflow' => false, 'scrolled' => true,
        'head' => ['Paid impr.', 'Reached', 'Visits'],
        'cells' => ['20', '75.0% 15 visits', '15'],
        'aligned' => true,
    ]);

    $page->assertNoJavaScriptErrors();
})->with([1440, 390]);
