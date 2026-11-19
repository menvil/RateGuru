<?php

use App\Enums\GameAudience;
use App\Enums\GameEventType;
use App\Filament\Pages\Analytics\AnalyticsTab;
use App\Filament\Pages\GrowthAnalyticsPage;
use App\Filament\Pages\TrafficSourcesPage;
use App\Models\GameAttempt;
use App\Models\GameEvent;
use App\Models\GameVersion;
use App\Models\TrafficCampaign;
use App\Models\TrafficSetting;
use App\Models\TrafficSource;
use App\Models\TrafficSpendDay;
use App\Models\TrafficVisit;
use App\Models\User;
use App\Support\Analytics\AnalyticsSource;
use App\Support\Traffic\Capture\BotSignals;
use App\Support\Traffic\Reports\ReportPeriodMemory;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * Growth → Analytics and its Overview: an active administrator's who may
 * manage traffic sources, reading no visits until the page is in a browser;
 * everything in the address, anything it can't read the default; a strip of
 * the tabs built so far and no other; what it says about what isn't
 * recorded, and when nothing can be counted; the tiles against the window
 * before, the funnel whose accounts are the tile's, and the sources table,
 * Referral performance and Data notes of the period and the device alone —
 * a row filtering the whole page and dropping a campaign of another source;
 * each tab read in a bounded number of queries.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00'));
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

/** Records visits — and the site's own pages' too, unless told otherwise — as the Tracking card's switches would. */
function recordAnalyticsVisits(bool $site = true): void
{
    TrafficSetting::current()->forceFill(['capture_enabled' => true, 'site_capture_enabled' => $site])->save();
    TrafficSetting::forgetCaptureFlags();
}

/** The open Overview's figures, read as the page reads them once it is in a browser. */
function analyticsOverview(array $query = []): array
{
    return Livewire::withQueryParams($query)->test(GrowthAnalyticsPage::class)->call('load')->viewData('data');
}

it('opens for an active administrator only, reading no visits until the page is in a browser, and answers nobody else', function () {
    analyticsVisit('ann', ['source_key' => 'reddit:organic']);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->get(GrowthAnalyticsPage::getUrl(['source' => 'reddit:organic', 'period' => '7d']))->assertOk()
        ->assertSee('Where visitors come from and what they do. RateGuru counts every figure here itself.')
        ->assertSee('wire:init="load"', false)
        ->assertSee('Source: Reddit · organic_social')
        // The period's days, in the top bar.
        ->assertSee('data-testid="analytics-range"', false)
        ->assertSee('12 Nov — 18 Nov 2026')
        ->assertDontSee('Acquisition funnel');

    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $query): bool => str_contains($query, 'traffic_visits'))->values()->all())->toBe([]);
    DB::disableQueryLog();

    Livewire::withQueryParams([])->test(GrowthAnalyticsPage::class)->assertViewHas('ready', false)->assertViewHas('data', null)
        ->call('load')->assertSee('Acquisition funnel');

    $this->actingAs(User::factory()->moderator()->create())->get(GrowthAnalyticsPage::getUrl())->assertForbidden();
    $this->actingAs(User::factory()->admin()->limited()->create())->get(GrowthAnalyticsPage::getUrl())->assertForbidden();

    // Every request of a page already open asks again.
    $this->actingAs($this->admin);
    $page = Livewire::withQueryParams([])->test(GrowthAnalyticsPage::class)->call('load');
    $this->actingAs(User::factory()->moderator()->create());

    $page->call('pickSource', 'reddit:organic')->assertForbidden();
});

it('keeps the tab, period, filters and sorting in the address, and reads anything it can’t as the default', function () {
    Livewire::withQueryParams(['tab' => 'email', 'period' => 'forever', 'device' => 'tablet', 'source' => "reddit\n:organic", 'campaign' => 'launch', 'sort' => 'loudest'])
        ->test(GrowthAnalyticsPage::class)
        ->assertSet('tab', 'overview')
        ->assertSet('period', '30d')
        ->assertSet('from', '')
        ->assertSet('to', '')
        ->assertSet('device', '')
        ->assertSet('source', '')
        ->assertSet('campaign', '')
        ->assertSet('sort', 'visitors');

    // A range longer than Analytics reads at a time is the preset named instead.
    Livewire::withQueryParams(['period' => '7d', 'from' => '2026-07-01', 'to' => '2026-11-01'])->test(GrowthAnalyticsPage::class)
        ->assertSet('period', '7d')
        ->assertSet('from', '')
        ->assertViewHas('periodPicker', fn (array $picker): bool => $picker['label'] === 'Last 7 days' && $picker['dates'] === '12 Nov — 18 Nov 2026' && $picker['most'] === 93)
        // Its calendar can't reach past that, and says so.
        ->assertSeeHtml('most: 93,')
        ->assertSee('Up to 93 days at a time');

    $page = Livewire::withQueryParams(['from' => '2026-10-01', 'to' => '2026-10-31', 'device' => 'mobile', 'source' => 'paid:12', 'campaign' => 'tag:launch', 'sort' => 'registration'])
        ->test(GrowthAnalyticsPage::class)
        ->assertSet('from', '2026-10-01')
        ->assertSet('to', '2026-10-31')
        ->assertSet('device', 'mobile')
        ->assertSet('source', 'paid:12')
        ->assertSet('campaign', 'tag:launch')
        ->assertSet('sort', 'registration')
        ->assertViewHas('periodPicker', fn (array $picker): bool => $picker['label'] === 'Custom range' && $picker['dates'] === '01 Oct — 31 Oct 2026');

    // A period chosen is read back the same way, and the top bar is told its days.
    $page->call('applyRange', '2026-06-01', '2026-11-01')->assertSet('period', '30d')->assertSet('from', '')->assertSet('to', '')
        ->call('choosePeriod', 'last-month')->assertSet('period', 'last-month')->assertDispatched('rg-admin-analytics-range', text: '01 Oct — 31 Oct 2026')
        ->call('applyRange', '2026-11-01', '2026-11-10')->assertSet('from', '2026-11-01')->assertSet('to', '2026-11-10')->assertDispatched('rg-admin-analytics-range', text: '01 Nov — 10 Nov 2026')
        ->set('sort', 'sideways')->assertSet('sort', 'visitors')
        ->set('device', 'fridge')->assertSet('device', '');

    expect(GrowthAnalyticsPage::topbar(Request::create('/admin/analytics', 'GET', ['period' => 'yesterday'])))->toContain('17 Nov 2026');
});

it('tells the top bar the days it read the figures of, a preset that rolled over at midnight included', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 23:59:00'));
    $page = Livewire::withQueryParams(['period' => '7d'])->test(GrowthAnalyticsPage::class)->call('load')
        ->assertDispatched('rg-admin-analytics-range', text: '12 Nov — 18 Nov 2026');

    // Left open overnight, then Refresh: the figures and the top bar both move to the new day.
    $this->travelTo(CarbonImmutable::parse('2026-11-19 00:05:00'));

    $page->call('refreshFigures')
        ->assertViewHas('range', '13 Nov — 19 Nov 2026')
        ->assertDispatched('rg-admin-analytics-range', text: '13 Nov — 19 Nov 2026');
});

it('offers Today first among the presets: today’s visits alone, hour by hour, against yesterday by this time', function () {
    analyticsVisit('ann', ['network_day' => '2026-11-18', 'arrived_at' => '2026-11-18 09:00:00']);
    analyticsVisit('bob', ['network_day' => '2026-11-17', 'arrived_at' => '2026-11-17 09:00:00']);
    analyticsVisit('cat', ['network_day' => '2026-11-17', 'arrived_at' => '2026-11-17 11:00:00']);
    // Later in the day than it now is: today has had no such hours yet, so they don't count against it.
    analyticsVisit('dan', ['network_day' => '2026-11-17', 'arrived_at' => '2026-11-17 15:00:00']);
    analyticsVisit('eve', ['network_day' => '2026-11-17', 'arrived_at' => '2026-11-17 20:00:00']);

    $page = Livewire::withQueryParams(['period' => 'today'])->test(GrowthAnalyticsPage::class)->call('load')
        ->assertSet('period', 'today')
        ->assertSet('from', '')
        ->assertViewHas('periodPicker', fn (array $picker): bool => array_key_first($picker['presets']) === 'today' && $picker['value'] === 'today' && $picker['label'] === 'Today'
            && $picker['dates'] === '18 Nov 2026' && [$picker['from'], $picker['to']] === ['2026-11-18', '2026-11-18'])
        ->assertDispatched('rg-admin-analytics-range', text: '18 Nov 2026');
    $data = $page->viewData('data');
    $visitors = collect($data['kpis'])->firstWhere('label', 'Visitors');

    $bars = array_column($data['chart']['bars'], 'title');

    expect($visitors['value'])->toBe('1')
        ->and($visitors['change'])->toBe('−50% vs yesterday by this time')
        ->and($data['chart']['title'])->toBe('Visitors per hour')
        // The hours of today not yet begun count nobody: they are still to come; the hour now running counts.
        ->and([$bars[9], $bars[12], $bars[13], $bars[23]])->toBe([
            '18 Nov 2026 · 09:00–10:00 UTC · 1 visitor',
            '18 Nov 2026 · 12:00–13:00 UTC · 0 visitors',
            '18 Nov 2026 · 13:00–14:00 UTC · still to come',
            '18 Nov 2026 · 23:00–24:00 UTC · still to come',
        ])
        ->and(GrowthAnalyticsPage::topbar(Request::create('/admin/analytics', 'GET', ['period' => 'today'])))->toContain('18 Nov 2026');
});

it('opens on the period the administrator last chose when the address names none, an address naming one winning and kept in turn', function () {
    $open = fn (array $query = []) => Livewire::withQueryParams($query)->test(GrowthAnalyticsPage::class);

    // Nothing chosen yet: the default. A preset chosen is then what an address naming none opens on, the top bar's days with it.
    $open()->assertSet('period', '30d')->call('choosePeriod', 'today');
    $open()->assertSet('period', 'today')->assertSet('from', '')->assertViewHas('periodPicker', fn (array $picker): bool => $picker['label'] === 'Today');
    expect(GrowthAnalyticsPage::topbar(Request::create('/admin/analytics')))->toContain('18 Nov 2026');

    // So is a range, chosen on any tab.
    $open(['tab' => 'geo'])->call('applyRange', '2026-11-01', '2026-11-10');
    $open()->assertSet('period', '30d')->assertSet('from', '2026-11-01')->assertSet('to', '2026-11-10')
        ->assertViewHas('periodPicker', fn (array $picker): bool => $picker['label'] === 'Custom range' && $picker['dates'] === '01 Nov — 10 Nov 2026');

    // An address naming a period wins over it — the default too — and is kept in turn; a link carrying its own days opens on them.
    $open(['period' => '7d'])->assertSet('period', '7d')->assertSet('from', '');
    $open()->assertSet('period', '7d');
    $open(['period' => '30d'])->assertSet('period', '30d');
    $open()->assertSet('period', '30d');
    $open(['tab' => 'games', 'from' => '2026-11-05', 'to' => '2026-11-12'])->assertSet('from', '2026-11-05')->assertSet('to', '2026-11-12');

    // Only opening the page and the picker keep a period: another filter changed on a page opened before leaves the last choice alone.
    $earlier = $open(['period' => 'yesterday']);
    $open(['period' => '90d']);
    $earlier->set('device', 'mobile')->assertSet('period', 'yesterday');
    $open()->assertSet('period', '90d');

    // Each administrator keeps their own, and each report page's picker its own.
    $this->actingAs(User::factory()->admin()->create());
    $open()->assertSet('period', '30d');
    $this->actingAs($this->admin);
    $open()->assertSet('period', '90d');
    Livewire::withQueryParams([])->test(TrafficSourcesPage::class)->assertSet('period', '30d');
});

it('opens on the default, silently, once the period last chosen can no longer be read', function () {
    Livewire::withQueryParams(['from' => '2026-11-01', 'to' => '2026-11-10'])->test(GrowthAnalyticsPage::class);

    // A range now older than the visits are kept: the default, and the default kept instead.
    $this->travelTo(CarbonImmutable::parse('2028-01-01 12:00:00'));
    Livewire::withQueryParams([])->test(GrowthAnalyticsPage::class)->assertSet('period', '30d')->assertSet('from', '')->assertSet('to', '')
        ->assertViewHas('periodPicker', fn (array $picker): bool => $picker['label'] === 'Last 30 days');
    expect(GrowthAnalyticsPage::topbar(Request::create('/admin/analytics')))->toContain('03 Dec 2027 — 01 Jan 2028')
        ->and(ReportPeriodMemory::recall(Request::create('/admin/analytics'), GrowthAnalyticsPage::PERIOD_MEMORY))->toBe(['period' => '30d', 'from' => '', 'to' => '']);

    // A preset the page doesn't offer, however it came to be kept.
    ReportPeriodMemory::remember(GrowthAnalyticsPage::PERIOD_MEMORY, 'forever', '', '');
    Livewire::withQueryParams([])->test(GrowthAnalyticsPage::class)->assertSet('period', '30d')->assertSet('from', '');
});

it('lists only the tabs that are built, each drawn from its own view and figures', function () {
    analyticsVisit('ann');

    expect(GrowthAnalyticsPage::TABS)->toBe(['overview' => 'Overview', 'engagement' => 'Site engagement', 'campaigns' => 'Campaigns', 'links' => 'Tracking links', 'landings' => 'Landings', 'games' => 'Games', 'experiments' => 'Experiments', 'retention' => 'Retention', 'geo' => 'Geography', 'journeys' => 'Journeys'])
        ->and(array_keys(GrowthAnalyticsPage::TAB_DATA))->toBe(array_keys(GrowthAnalyticsPage::TABS));

    foreach (GrowthAnalyticsPage::TABS as $tab => $label) {
        expect(view()->exists('filament.pages.analytics.'.$tab))->toBeTrue("{$label} has no view")
            ->and(is_subclass_of(GrowthAnalyticsPage::TAB_DATA[$tab], AnalyticsTab::class))->toBeTrue("{$label} reads no figures");

        $page = Livewire::withQueryParams(['tab' => $tab])->test(GrowthAnalyticsPage::class)->call('load')->assertSet('tab', $tab);

        expect($page->viewData('data'))->toBeArray()->not->toBeEmpty()
            ->and(collect($page->viewData('tabs'))->pluck('label')->all())->toBe(array_values(GrowthAnalyticsPage::TABS));
    }

    // The reference's other tabs arrive with their own screens, never as an empty tab first.
    foreach (['engagement', 'campaigns', 'links', 'landings', 'games', 'experiments', 'retention', 'geo', 'journeys', 'email'] as $later) {
        if (! array_key_exists($later, GrowthAnalyticsPage::TABS)) {
            Livewire::withQueryParams(['tab' => $later])->test(GrowthAnalyticsPage::class)->assertSet('tab', 'overview');
        }
    }
});

it('says what isn’t recorded, and since when visits are', function () {
    analyticsVisit('ann', ['network_day' => '2026-11-15']);
    $notices = fn (array $query = []): array => collect(Livewire::withQueryParams($query)->test(GrowthAnalyticsPage::class)->call('load')->viewData('notices'))->pluck('text')->all();

    expect($notices())->toBe([
        'Record visits is off, so Analytics has nothing new to count. Switch it on under Traffic sources → Sources → Tracking.',
        'Visits are recorded since 15 Nov 2026.',
    ]);

    recordAnalyticsVisits(site: false);
    expect($notices())->toBe(['Only game visits and paid clicks are recorded. Switch on Record site visits to see the whole site.', 'Visits are recorded since 15 Nov 2026.']);

    recordAnalyticsVisits();
    expect($notices())->toBe(['Visits are recorded since 15 Nov 2026.'])
        // A period from that day on needs no word about it.
        ->and($notices(['period' => 'yesterday']))->toBe([])
        // Nor is it known before the page reads anything.
        ->and(Livewire::withQueryParams([])->test(GrowthAnalyticsPage::class)->viewData('notices'))->toBe([]);
});

it('says so when nothing was ever recorded, when the period comes before the first visit, and when nobody matches the filters', function () {
    Livewire::withQueryParams([])->test(GrowthAnalyticsPage::class)->call('load')
        ->assertViewHas('state', 'nothing')
        ->assertSee('No visits recorded yet')
        ->assertSee('None has been recorded yet.')
        ->assertDontSee('Acquisition funnel');

    // A switch that is off is the notice's to say, with the one way to Tracking.
    $off = Livewire::withQueryParams([])->test(GrowthAnalyticsPage::class)->call('load');

    expect(substr_count($off->html(), 'Open Tracking'))->toBe(1);

    recordAnalyticsVisits();

    Livewire::withQueryParams([])->test(GrowthAnalyticsPage::class)->call('load')
        ->assertViewHas('state', 'nothing')
        ->assertViewHas('notices', [])
        ->assertDontSee('Open Tracking');

    analyticsVisit('ann', ['network_day' => '2026-11-15', 'source_key' => 'reddit:organic']);

    Livewire::withQueryParams(['from' => '2026-10-01', 'to' => '2026-10-31'])->test(GrowthAnalyticsPage::class)->call('load')
        ->assertViewHas('state', 'early')
        ->assertSee('Too early for these days')
        ->assertSee('Visits are recorded since 15 Nov 2026, after 01 Oct — 31 Oct 2026. Pick a later period.')
        ->assertDontSee('Acquisition funnel');

    // The card says it; no notice repeats it above.
    expect(collect(Livewire::withQueryParams(['from' => '2026-10-01', 'to' => '2026-10-31'])->test(GrowthAnalyticsPage::class)->call('load')->viewData('notices'))->pluck('text')->all())
        ->not->toContain('Visits are recorded since 15 Nov 2026.');

    // The last 30 days named: the range above is the period last chosen, which an address naming none would open on.
    Livewire::withQueryParams(['period' => '30d', 'source' => 'google:organic', 'device' => 'desktop'])->test(GrowthAnalyticsPage::class)->call('load')
        ->assertSee('No visitors for these filters')
        ->assertSee('Nothing matches in 20 Oct — 18 Nov 2026. Pick a longer range or clear the filters.')
        ->assertSee('Clear filters')
        ->assertDontSee('Acquisition funnel')
        ->assertDontSee('Referral performance')
        ->call('clearFilters')
        ->assertSet('source', '')
        ->assertSet('device', '')
        ->assertSee('Acquisition funnel');
});

it('draws the tiles against the window before, and the funnel whose accounts are the tile’s, of new visitors from Signed up on', function () {
    // The window before: two people, one account.
    analyticsVisit('old-1', ['network_day' => '2026-09-25']);
    analyticsVisit('old-2', ['network_day' => '2026-09-26', 'account_at' => '2026-09-26 10:30:00', 'signup_at' => '2026-09-26 10:20:00']);

    $did = ['started_at' => '2026-11-15 10:01:00', 'completed_at' => '2026-11-15 10:05:00', 'goal_at' => '2026-11-15 10:05:00', 'signup_form_at' => '2026-11-15 10:06:00'];
    analyticsVisit('ann', [...$did, 'signup_at' => '2026-11-15 10:07:00', 'account_at' => '2026-11-15 10:08:00', 'activated_at' => '2026-11-17 09:00:00']);
    analyticsVisit('bob', [...$did, 'signup_at' => '2026-11-15 10:07:00', 'account_at' => '2026-11-15 10:08:00']);
    analyticsVisit('cat', [...$did, 'signup_at' => '2026-11-15 10:07:00']);
    analyticsVisit('dan', ['started_at' => '2026-11-15 10:01:00']);
    // Known as they came: a visitor, never a new one.
    analyticsVisit('eve', ['known' => true, 'started_at' => '2026-11-15 10:01:00']);

    $version = GameVersion::factory()->create();
    GameAttempt::factory()->count(3)->create(['version_id' => $version->id, 'traffic_visit_id' => TrafficVisit::query()->where('visitor_hash', hash('sha256', 'ann'))->value('id')]);

    $data = analyticsOverview();
    $kpis = collect($data['kpis'])->keyBy('label');

    expect($kpis->map(fn (array $kpi): string => $kpi['value'])->all())->toBe([
        'Visitors' => '5',
        'New visitors' => '4',
        'Game starters' => '5',
        'Game completers' => '3',
        'Accounts created' => '2',
        'Activated users' => '1',
        'Visitor → registration' => '50.0%',
        'Visitor → activation' => '25.0%',
    ])->and($kpis['Visitors']['change'])->toBe('+150% vs the previous 30 days')
        ->and($kpis['Accounts created']['change'])->toBe('+100% vs the previous 30 days')
        ->and($kpis['Activated users']['change'])->toBe('No earlier data')
        ->and($kpis['Visitor → registration']['change'])->toBeNull()
        ->and($kpis['New visitors']['unit'])->toBe('80.0% of visitors')
        ->and($kpis['Game starters']['unit'])->toBe('3 attempts in total')
        ->and($kpis['Game completers']['unit'])->toBe('60.0% of starters')
        ->and($data['funnel']['note'])->toBe('Last 30 days. Each step counts unique visitors, not attempts. Sign-ups, accounts and activations are shares of new visitors.');

    $steps = collect($data['funnel']['steps'])->keyBy('name');

    expect($steps->keys()->all())->toBe(['Landing visitors', 'Game starters', 'Game completers', 'Reached the goal', 'Saw the sign-up form', 'Signed up', 'Accounts confirmed', 'Activated users'])
        // Step seven is the tile.
        ->and($steps['Accounts confirmed']['count'])->toBe($kpis['Accounts created']['value'])
        ->and($steps['Activated users']['count'])->toBe($kpis['Activated users']['value'])
        ->and($steps->map(fn (array $step): string => $step['previous'].' | '.$step['first'])->all())->toBe([
            'Landing visitors' => '— | 100.0%',
            'Game starters' => '100.0% | 100.0%',
            'Game completers' => '60.0% | 60.0%',
            'Reached the goal' => '100.0% | 60.0%',
            // Shown on the site as well as after a goal: no share of the goal's visitors.
            'Saw the sign-up form' => '— | 60.0%',
            // From here on, shares of the four new visitors.
            'Signed up' => '100.0% | 75.0%',
            'Accounts confirmed' => '66.7% | 50.0%',
            'Activated users' => '50.0% | 25.0%',
        ]);

    // With nothing kept before the period, no change is drawn at all.
    analyticsVisit('first', ['network_day' => '2025-10-16']);

    expect(collect(analyticsOverview(['from' => '2025-10-15', 'to' => '2025-10-20'])['kpis'])->pluck('change')->filter()->all())->toBe([]);
});

it('marks Activated users too early while the period’s last accounts can still activate', function () {
    analyticsVisit('ann', ['network_day' => '2026-11-05']);

    $flag = fn (array $query): ?array => collect(analyticsOverview($query)['kpis'])->firstWhere('label', 'Activated users')['flag'];

    expect($flag([]))->toBe(['label' => 'Too early', 'hint' => 'Accounts from 11 Nov on can still activate'])
        ->and($flag(['from' => '2026-11-01', 'to' => '2026-11-11']))->toBe(['label' => 'Too early', 'hint' => 'Accounts from 11 Nov on can still activate'])
        ->and($flag(['from' => '2026-11-01', 'to' => '2026-11-10']))->toBeNull();
});

it('draws the sources table, Referral performance and Data notes for the period and the device alone, whatever source and campaign are chosen', function () {
    $exo = TrafficSource::factory()->create(['name' => 'ExoClick tests']);

    foreach (range(1, 31) as $n) {
        analyticsVisit('reddit-'.$n, ['source_key' => 'reddit:organic', 'campaign_key' => $n <= 5 ? 'tag:spring' : null, 'utm_source' => $n <= 20 ? 'reddit' : null, 'started_at' => $n <= 12 ? '2026-11-15 10:01:00' : null, 'account_at' => $n <= 3 ? '2026-11-15 10:09:00' : null]);
    }

    foreach (range(1, 4) as $n) {
        analyticsVisit('google-'.$n, ['source_key' => 'google:organic', 'device' => 'desktop', 'account_at' => $n === 1 ? '2026-11-15 10:09:00' : null]);
    }

    $shared = analyticsVisit('shared-1', ['source_key' => AnalyticsSource::SHARED, 'started_at' => '2026-11-15 10:01:00']);
    $paid = analyticsVisit('paid-1', ['source_key' => 'paid:'.$exo->id, 'source_id' => $exo->id, 'channel' => 'network', 'spend' => '0.50']);
    TrafficVisit::factory()->create(['source_id' => $exo->id, 'arrived_at' => '2026-11-15 11:00:00', 'network_day' => '2026-11-15', 'bot_flags' => BotSignals::CRAWLER, 'spend' => '0.25']);
    TrafficVisit::factory()->create(['source_id' => $exo->id, 'arrived_at' => '2026-11-15 11:00:00', 'network_day' => '2026-11-15', 'spend' => '0.25']);
    analyticsVisit('direct-1');
    // A day's spend no visit took yet counts as Traffic sources counts it.
    TrafficSpendDay::factory()->create(['source_id' => $exo->id, 'network_day' => '2026-11-16', 'amount' => '2', 'allocated_amount' => '0.5', 'counted' => true]);

    $attempt = GameAttempt::factory()->create(['version_id' => GameVersion::factory()->create()->id, 'traffic_visit_id' => $paid->id]);
    (new GameEvent)->forceFill(['game_id' => $attempt->game_id, 'version_id' => $attempt->version_id, 'attempt_id' => $attempt->id, 'audience' => GameAudience::Anonymous, 'visitor_hash' => $attempt->visitor_hash, 'type' => GameEventType::ShareClicked, 'provider' => 'reddit', 'occurred_at' => now()])->save();

    $all = analyticsOverview();
    $rows = collect($all['sources']['rows'])->keyBy('name');

    expect($rows->keys()->all())->toBe(['Reddit', 'Google', 'Direct / Unknown', 'ExoClick tests', 'Shared results'])
        ->and($rows['Reddit']['visitors'])->toBe('31')
        ->and($rows['Reddit']['cells'])->toBe([['12', '38.7%'], ['0', '—'], ['3', '9.7%'], ['0', '0.0%']])
        ->and($rows['Reddit']['basis'])->toBe('65% tagged · 35% referrer')
        ->and($rows['Reddit']['medium'])->toBe('organic_social')
        // Below thirty to divide by, a rate is no rate.
        ->and($rows['Google']['cells'][2])->toBe(['1', '—'])
        ->and($rows['ExoClick tests']['group'])->toBe('Paid')
        ->and($rows['ExoClick tests']['basis'])->toBe('rg_src link · click ID')
        ->and($all['sources']['total']['visitors'])->toBe('38')
        ->and($all['sources']['total']['unattributed'])->toBe('2.6% unattributed')
        ->and(collect($all['referral'])->pluck('value', 'label')->all())->toBe([
            'Share clicks' => '1', 'Results shared' => '1', 'Visits from shared results' => '1', 'Game starts' => '1', 'Completions' => '0', 'Accounts' => '0', 'Activated users' => '0',
        ])
        ->and(collect($all['notes'])->pluck('text', 'title')->all())->toBe([
            'Unattributed' => '2.6% of visitors arrived without tags or a referrer. They stay Direct / Unknown; no source is guessed.',
            'Crawlers and previews' => '1 paid click came from crawlers and link previews. They aren’t counted as visitors.',
            'Pages that never ran' => '1 paid click never ran the page in a browser, so nobody is counted for them.',
            'Spend' => '$2.50 went to paid sources in this period. Unpaid visitors cost nothing.',
        ]);

    $narrowed = analyticsOverview(['source' => 'reddit:organic', 'campaign' => 'tag:spring']);
    $unselected = fn (array $sources): array => [...$sources, 'rows' => array_map(fn (array $row): array => [...$row, 'selected' => false], $sources['rows'])];

    expect($unselected($narrowed['sources']))->toBe($unselected($all['sources']))
        ->and($narrowed['referral'])->toBe($all['referral'])
        ->and($narrowed['notes'])->toBe($all['notes'])
        // Everything else follows the source and campaign chosen.
        ->and($narrowed['kpis'][0]['value'])->toBe('5')
        ->and(collect($narrowed['sources']['rows'])->firstWhere('selected', true)['name'])->toBe('Reddit')
        ->and($narrowed['funnel']['note'])->toStartWith('Reddit · spring · Last 30 days.');

    // The device narrows them all.
    $desktop = analyticsOverview(['device' => 'desktop']);

    expect(collect($desktop['sources']['rows'])->pluck('name')->all())->toBe(['Google'])
        ->and($desktop['sources']['total']['visitors'])->toBe('4')
        // A day's spend is no device's.
        ->and(collect($desktop['notes'])->pluck('text', 'title')['Spend'])->toBe('No paid source spent anything in this period. Unpaid visitors cost nothing.');
});

it('sorts the sources by visitors or a rate, the rows without one last and everyone else’s at the foot', function () {
    foreach (range(1, 40) as $n) {
        analyticsVisit('reddit-'.$n, ['source_key' => 'reddit:organic', 'started_at' => $n <= 8 ? '2026-11-15 10:01:00' : null]);
    }

    foreach (range(1, 32) as $n) {
        analyticsVisit('google-'.$n, ['source_key' => 'google:organic', 'started_at' => $n <= 24 ? '2026-11-15 10:01:00' : null]);
    }

    foreach (range(1, 5) as $n) {
        analyticsVisit('bing-'.$n, ['source_key' => 'bing:organic', 'started_at' => '2026-11-15 10:01:00']);
    }

    foreach (range(1, 11) as $n) {
        analyticsVisit('host-'.$n, ['source_key' => 'host.example'.$n.'.com:referral']);
    }

    $names = fn (string $sort): array => collect(analyticsOverview(['sort' => $sort])['sources']['rows'])->pluck('name')->all();

    // The hosts' order among themselves is the engine's collation's: only their place is asserted.
    expect($names('visitors'))->toHaveCount(11)
        ->and(array_slice($names('visitors'), 0, 3))->toBe(['Reddit', 'Google', 'Bing'])
        ->and(array_slice($names('visitors'), -1))->toBe(['Other referrals'])
        // Bing's five visitors give no rate to sort by: it goes after the rates.
        ->and(array_slice($names('start'), 0, 3))->toBe(['Google', 'Reddit', 'Bing'])
        ->and(array_slice($names('start'), -1))->toBe(['Other referrals']);
});

it('filters the whole page by a source row, a second click clearing it, and drops a campaign of another source', function () {
    $exo = TrafficSource::factory()->create(['name' => 'ExoClick tests']);
    $pop = TrafficSource::factory()->create(['name' => 'PopAds main']);
    $campaign = TrafficCampaign::factory()->create(['source_id' => $exo->id, 'name' => 'US popunder']);
    analyticsVisit('ann', ['source_key' => 'reddit:organic', 'campaign_key' => 'tag:spring']);
    analyticsVisit('bob', ['source_key' => 'google:organic']);
    analyticsVisit('cat', ['source_key' => 'paid:'.$exo->id, 'campaign_key' => 'paid:'.$campaign->id]);
    analyticsVisit('dan', ['source_key' => 'paid:'.$pop->id]);

    $page = Livewire::withQueryParams([])->test(GrowthAnalyticsPage::class)->call('load')
        ->call('pickSource', 'reddit:organic')
        ->assertSet('source', 'reddit:organic')
        ->assertSee('Source: Reddit · organic_social')
        ->assertViewHas('data', fn (array $data): bool => $data['kpis'][0]['value'] === '1' && collect($data['sources']['rows'])->firstWhere('selected', true)['key'] === 'reddit:organic')
        ->call('pickSource', 'reddit:organic')
        ->assertSet('source', '')
        ->assertDontSee('Source: Reddit');

    // A campaign named by its tag stays with a source it came with, and goes with any other.
    $page->set('campaign', 'tag:spring')
        ->call('pickSource', 'reddit:organic')->assertSet('campaign', 'tag:spring')
        ->call('pickSource', 'google:organic')->assertSet('source', 'google:organic')->assertSet('campaign', '')
        ->call('clearSource')->assertSet('source', '');

    // A paid campaign stays with its own source and goes with any other, chosen from the menu too.
    $page->set('campaign', 'paid:'.$campaign->id)
        ->call('pickSource', 'paid:'.$exo->id)->assertSet('campaign', 'paid:'.$campaign->id)->assertSee('Source: ExoClick tests · paid_ads')
        ->set('source', 'paid:'.$pop->id)->assertSet('campaign', '');
});

it('offers the period’s sources and the campaigns of the chosen source, and only what is chosen before the page is in a browser', function () {
    $exo = TrafficSource::factory()->create(['name' => 'ExoClick tests']);
    $pop = TrafficSource::factory()->create(['name' => 'PopAds main']);
    $exoCampaign = TrafficCampaign::factory()->create(['source_id' => $exo->id, 'name' => 'US popunder']);
    TrafficCampaign::factory()->create(['source_id' => $pop->id, 'name' => null, 'external_id' => '4410']);
    analyticsVisit('ann', ['source_key' => 'reddit:organic', 'campaign_key' => 'tag:spring']);
    analyticsVisit('bob', ['source_key' => 'reddit:organic']);
    analyticsVisit('cat', ['source_key' => 'paid:'.$exo->id, 'campaign_key' => 'paid:'.$exoCampaign->id]);
    analyticsVisit('dan', ['source_key' => 'google:organic', 'campaign_key' => 'tag:brand']);

    $labels = fn ($page, string $key): array => collect($page->viewData($key))->pluck('label')->all();

    $before = Livewire::withQueryParams(['source' => 'paid:'.$pop->id])->test(GrowthAnalyticsPage::class);

    expect($labels($before, 'sourceOptions'))->toBe(['All sources', 'PopAds main · paid_ads'])
        ->and($labels($before, 'campaignOptions'))->toBe(['All campaigns']);

    $all = Livewire::withQueryParams([])->test(GrowthAnalyticsPage::class)->call('load');

    // The most people first, then by the key.
    expect($labels($all, 'sourceOptions'))->toBe(['All sources', 'Reddit · organic_social', 'Google · organic_search', 'ExoClick tests · paid_ads'])
        // With every source listed, a campaign names its source: networks' campaigns often share a name.
        ->and($labels($all, 'campaignOptions'))->toBe(['All campaigns', 'Campaign 4410 · PopAds main', 'US popunder · ExoClick tests', 'brand', 'spring']);

    $reddit = Livewire::withQueryParams(['source' => 'reddit:organic'])->test(GrowthAnalyticsPage::class)->call('load');

    expect($labels($reddit, 'campaignOptions'))->toBe(['All campaigns', 'spring']);

    $paid = Livewire::withQueryParams(['source' => 'paid:'.$pop->id])->test(GrowthAnalyticsPage::class)->call('load');

    expect($labels($paid, 'sourceOptions'))->toBe(['All sources', 'Reddit · organic_social', 'Google · organic_search', 'ExoClick tests · paid_ads', 'PopAds main · paid_ads'])
        ->and($labels($paid, 'campaignOptions'))->toBe(['All campaigns', 'Campaign 4410']);
});

it('says what RateGuru records on the Tracking card', function () {
    recordAnalyticsVisits(site: false);
    analyticsVisit('ann', ['network_day' => '2026-11-02', 'arrived_at' => '2026-11-02 08:00:00']);

    $rows = collect(analyticsOverview()['tracking']['rows'])->mapWithKeys(fn (array $row): array => [$row['name'] => $row['state']])->all();

    expect($rows)->toBe([
        'Record visits' => 'On',
        'Record site visits' => 'Off',
        'Send postbacks' => 'Off',
        'IP database' => $rows['IP database'],
        'API connections' => 'None',
        'Visits recorded since' => '02 Nov 2026',
    ])->and($rows['IP database'])->toBeIn(['Serving', 'None yet']);
});

it('marks the chart’s buckets on which a version was published, a paid campaign started or a comparison began', function () {
    analyticsVisit('ann', ['network_day' => '2026-11-12']);
    analyticsVisit('bob', ['network_day' => '2026-11-14']);
    analyticsVisit('cat', ['network_day' => '2026-11-14']);
    GameVersion::factory()->create(['number' => 4, 'published_at' => '2026-11-14 09:00:00']);

    $chart = analyticsOverview(['period' => '7d'])['chart'];

    expect($chart['title'])->toBe('Visitors per day')
        ->and($chart['ticks'])->toBe(['12 Nov', '15 Nov', '18 Nov'])
        ->and(collect($chart['bars'])->pluck('height')->all())->toBe([50.0, 0.0, 100.0, 0.0, 0.0, 0.0, 0.0])
        ->and(collect($chart['bars'])->pluck('marked')->all())->toBe([false, false, true, false, false, false, false])
        ->and($chart['bars'][2]['title'])->toEndWith('· 2 visitors · V4 of '.GameVersion::query()->sole()->game->title.' published')
        ->and($chart['markers'])->toBe(['14 Nov · V4 of '.GameVersion::query()->sole()->game->title.' published']);
});

it('reads a tab in a bounded number of queries, however many sources, campaigns and visitors there are', function () {
    // The figures as they are at each read: the read cache has tests of its own (AnalyticsReadCacheTest).
    config(['analytics.read_cache_minutes' => 0]);

    /** @return array{0: int, 1: mixed} how many queries the page read its figures in, and the page */
    $queries = function (array $query): array {
        $page = Livewire::withQueryParams($query)->test(GrowthAnalyticsPage::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $page->call('load');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$count, $page];
    };

    analyticsVisit('ann', ['source_key' => 'reddit:organic']);
    [$few] = $queries([]);

    $sources = TrafficSource::factory()->count(15)->create();
    $campaigns = [];

    foreach ($sources as $index => $source) {
        $campaigns[$index] = TrafficCampaign::factory()->create(['source_id' => $source->id]);

        foreach (range(1, 3) as $n) {
            analyticsVisit("paid-{$index}-{$n}", ['source_key' => 'paid:'.$source->id, 'campaign_key' => 'paid:'.$campaigns[$index]->id]);
            analyticsVisit("tag-{$index}-{$n}", ['source_key' => 'host.site'.$index.'.com:referral', 'campaign_key' => 'tag:launch-'.$index]);
        }
    }

    [$many, $everyone] = $queries([]);
    [$narrowed, $one] = $queries(['source' => 'paid:'.$sources[0]->id, 'campaign' => 'paid:'.$campaigns[0]->id]);

    // Each load read the larger fixture: everyone, and the first source's campaign alone.
    expect($everyone->viewData('data')['kpis'][0]['value'])->toBe('91');
    $one->assertSet('campaign', 'paid:'.$campaigns[0]->id);
    expect($one->viewData('data')['kpis'][0]['value'])->toBe('3');

    // The shell's filters and hints, the tiles now and before, the chart and its markers, the sources
    // table with everyone else's row, Referral, Data notes and the Tracking card: never one per row.
    expect($few)->toBeLessThanOrEqual(24)
        ->and($many)->toBeLessThanOrEqual(24)
        ->and($narrowed)->toBeLessThanOrEqual(24)
        ->and($many - $few)->toBeLessThanOrEqual(3);
});
