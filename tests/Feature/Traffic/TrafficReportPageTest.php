<?php

use App\Actions\Traffic\EnterSpendAction;
use App\Enums\TrafficBlockLevel;
use App\Enums\TrafficChannel;
use App\Enums\TrafficKnownReason;
use App\Filament\Pages\GrowthAnalyticsPage;
use App\Filament\Pages\TrafficSources\TrafficSourcesView;
use App\Filament\Pages\TrafficSourcesPage;
use App\Models\Game;
use App\Models\TrafficApiConnection;
use App\Models\TrafficBlock;
use App\Models\TrafficCampaign;
use App\Models\TrafficChanceRun;
use App\Models\TrafficConversion;
use App\Models\TrafficPostback;
use App\Models\TrafficPostbackRule;
use App\Models\TrafficPriceSet;
use App\Models\TrafficSetting;
use App\Models\TrafficSource;
use App\Models\TrafficSpendDay;
use App\Models\TrafficStepChance;
use App\Models\TrafficVisit;
use App\Models\TrafficVisitDetail;
use App\Models\User;
use App\Support\Analytics\JourneyTimeline;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Growth → Traffic sources → Report: the tree of each view opening level by
 * level, ten rows a level and the rest in one; Sites & zones as one list in
 * the block rule's segments, searched, sorted, paged and ticked across pages
 * to block; the block rule in place; and the filters every tab reads.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00'));
    $this->actingAs(User::factory()->admin()->create());
    // The fixtures' sites have a few dozen clicks each: this source judges bots from 30, not the general 100.
    $this->source = TrafficSource::factory()->create(['name' => 'PopAds main', 'block_bot_clicks' => 30]);
});

/** Visits from a site of the source: so many clicks, so many of them opening the page, each costing a cent. */
function siteClicks(TrafficSource $source, string $site, int $clicks, int $opened, array $extra = []): void
{
    TrafficVisit::factory()->count($clicks)->sequence(fn ($sequence): array => ['opened_at' => $sequence->index < $opened ? now() : null])->create([
        'source_id' => $source->id,
        'site_ref' => $site,
        'arrived_at' => '2026-11-16 10:00:00',
        'network_day' => '2026-11-16',
        'spend' => '0.01',
        ...$extra,
    ]);
}

it('opens the ad networks to their sources at first, and a source to its campaigns when asked', function () {
    $campaign = TrafficCampaign::factory()->create(['source_id' => $this->source->id, 'name' => 'US popunder']);
    siteClicks($this->source, '40123', 3, 3, ['campaign_id' => $campaign->id]);
    TrafficVisit::factory()->unpaid(TrafficChannel::Search)->create(['game_id' => Game::factory(), 'arrived_at' => '2026-11-16 10:00:00', 'network_day' => '2026-11-16', 'opened_at' => now()]);

    $labels = fn ($page): array => collect($page->viewData('tree'))->pluck('label')->filter()->values()->all();
    $page = Livewire::test(TrafficSourcesPage::class)->call('load')
        ->assertSeeInOrder(['Ad networks', 'PopAds main', 'Unpaid', '1 channel', 'costs nothing', 'Total']);

    // The campaign filter names every campaign; the tree names one only once its source is opened.
    expect($labels($page))->toBe(['Ad networks', 'PopAds main', 'Unpaid']);

    $page->call('toggleNode', 'type=network|source='.$this->source->id);

    expect($labels($page))->toBe(['Ad networks', 'PopAds main', 'US popunder', 'Unpaid']);

    $page->call('toggleNode', 'type=network')->assertSet('open', []);

    expect($labels($page))->toBe(['Ad networks', 'Unpaid']);
});

it('shows ten rows a level and folds the rest into one row that opens a source’s sites as the list', function () {
    $campaign = TrafficCampaign::factory()->create(['source_id' => $this->source->id]);

    foreach (range(1, 12) as $site) {
        siteClicks($this->source, (string) (40100 + $site), $site, $site, ['campaign_id' => $campaign->id]);
    }

    Livewire::test(TrafficSourcesPage::class)->call('load')
        ->set('open', ['type=network', 'type=network|source='.$this->source->id, 'type=network|source='.$this->source->id.'|campaign='.$campaign->id])
        ->assertSee('site 40112')
        ->assertDontSee('site 40101')
        ->assertSee('+ 2 more site ids · open as a list')
        ->call('listSites', $this->source->id, 'site')
        ->assertSet('reportView', 'sites')
        ->assertSet('listBy', 'site')
        ->assertSet('source', (string) $this->source->id)
        ->assertSee('site 40101');
});

it('lists sites and zones in the block rule’s segments, searched, sorted and paged', function () {
    TrafficSetting::current()->forceFill(['block_bot_clicks' => 5, 'block_open_below' => 50])->save();
    // The general rule's 5 clicks, not the 30 this source keeps elsewhere.
    $this->source->forceFill(['block_bot_clicks' => null])->save();
    siteClicks($this->source, '50001', 6, 1);
    siteClicks($this->source, '50002', 4, 4);

    foreach (range(1, 26) as $site) {
        siteClicks($this->source, (string) (60000 + $site), 1, 1);
    }

    $page = Livewire::withQueryParams(['view' => 'sites', 'list' => 'site'])->test(TrafficSourcesPage::class)->call('load');

    expect($page->viewData('list')['counts'])->toMatchArray(['all' => 28, 'block' => 1, 'early' => 27])
        ->and($page->viewData('worthBlocking'))->toBe(1);

    $page->assertSee('Likely bots')
        ->assertSee('1–25 of 28')
        ->call('goToPage', 2)
        ->assertSee('26–28 of 28')
        ->set('segment', 'block')
        ->assertSee('site 50001')
        ->assertDontSee('site 50002')
        ->set('segment', 'all')
        ->set('q', '5000')
        ->assertSee('site 50002')
        ->assertDontSee('site 60001')
        ->call('sortBy', 'visits')
        ->assertSet('sort', 'visits')
        ->assertSeeInOrder(['site 50001', 'site 50002'])
        ->call('sortBy', 'visits')
        ->assertSet('dir', 'asc')
        ->assertSeeInOrder(['site 50002', 'site 50001']);
});

it('ticks sites across pages and blocks them by hand, then unblocks them from Blocked', function () {
    foreach (range(1, 27) as $site) {
        siteClicks($this->source, (string) (70000 + $site), 1, 1);
    }

    $page = Livewire::withQueryParams(['view' => 'sites', 'list' => 'site'])->test(TrafficSourcesPage::class)->call('load')
        ->call('togglePage')
        ->assertSee('25 selected')
        ->call('selectMatching')
        ->assertSee('27 selected')
        ->call('blockSelected')
        ->assertSet('selected', []);

    expect(TrafficBlock::query()->where('source_id', $this->source->id)->where('level', TrafficBlockLevel::Site)->count())->toBe(27);

    $page->set('segment', 'blocked')
        ->assertSee('Blocked')
        ->call('toggleSelected', $this->source->id.':site:70001')
        ->call('unblockSelected');

    expect(TrafficBlock::query()->count())->toBe(26);
});

it('never ticks a row that is not a site or zone key', function () {
    Livewire::withQueryParams(['view' => 'sites'])->test(TrafficSourcesPage::class)
        ->call('toggleSelected', 'anything')
        ->call('toggleSelected', '12:campaign:4')
        ->assertSet('selected', []);
});

it('keeps the block rule in place and shows the sites and zones it picks out', function () {
    Livewire::test(TrafficSourcesPage::class)->call('load')
        ->call('toggleBlockRule')
        ->assertSee('Likely bots')
        ->set('settings.block_bot_clicks', 40)
        ->call('saveSettings')
        ->assertHasNoErrors()
        ->call('showWorthBlocking')
        ->assertSet('reportView', 'sites')
        ->assertSet('segment', 'block');

    expect(TrafficSetting::current()->block_bot_clicks)->toBe(40);
});

it('saves Worth blocking’s general thresholds — the share of the source’s average, the days after a first click and the multiple of a target — refused by their fields', function () {
    // PopAds main keeps the general rule here: only the banner network has its own.
    $this->source->forceFill(['block_bot_clicks' => null])->save();
    TrafficSource::factory()->create(['name' => 'Banner network', 'block_bot_clicks' => 60]);
    TrafficSource::factory()->placement()->create(['name' => 'Cat blog sidebar']);

    $page = Livewire::test(TrafficSourcesPage::class)->call('load')
        ->call('toggleBlockRule')
        ->assertSet('settings.block_open_below_average', 50)
        ->assertSet('settings.block_open_floor', 5)
        ->assertSet('settings.block_grace_days', 3)
        ->assertSet('settings.block_target_multiple', '2.0')
        ->assertSeeHtmlInOrder([
            'data-testid="traffic-block-rule"',
            'wire:model="settings.block_bot_clicks"', 'wire:model="settings.block_open_below"', 'wire:model="settings.block_open_below_average"', 'of the source’s own average, or than',
            'wire:model="settings.block_open_floor"', 'aria-label="Opened below, percent, whatever the source’s average"', '% whatever the average — 0 for no floor.',
            'wire:model="settings.block_roi_below"', 'wire:model="settings.block_target_multiple"', '× the target price of an account where its campaign or its source has one',
            'wire:model="settings.block_min_spend"', 'wire:model="settings.block_min_clicks"', 'wire:model="settings.block_grace_days"', 'days after its first click.',
            'data-testid="traffic-block-rule-own"', 'Banner network has thresholds of its own, set in the source’s editor → Blocking; the rest are judged by these.',
        ]);

    $page->set('settings.block_target_multiple', '0.2')->set('settings.block_grace_days', 31)->set('settings.block_open_below_average', '')
        ->call('saveSettings')
        ->assertHasErrors(['block_target_multiple', 'block_grace_days', 'block_open_below_average'])
        ->assertSee('The times the target price of an account field must be at least 0.5.');
    // Each refused field marked so, the rest as they were.
    expect($page->html())->toMatch('/<input class="[^"]*rg-admin-input--invalid"[^>]*wire:model="settings.block_grace_days"[^>]*aria-invalid="true"/')
        ->not->toMatch('/<input class="[^"]*rg-admin-input--invalid"[^>]*wire:model="settings.block_bot_clicks"/')
        ->and(TrafficSetting::current()->exists)->toBeFalse();

    // A multiple keeps one decimal place, and says so.
    $page->set('settings.block_target_multiple', '1.25')->call('saveSettings')
        ->assertHasErrors(['block_target_multiple'])
        ->assertSee('Use a number from 0.5 to 20 with at most one decimal place, such as 1.5.');

    $page->set('settings.block_target_multiple', '1.5')->set('settings.block_grace_days', 5)->set('settings.block_open_below_average', 40)
        ->call('saveSettings')
        ->assertHasNoErrors()
        ->assertSet('settings.block_target_multiple', '1.5');

    expect(TrafficSetting::current())->block_open_below_average->toBe(40)->block_grace_days->toBe(5)->block_target_multiple->toBe('1.5');
});

it('saves Worth blocking’s floor, 0 turning it off, refused outside 0 to 100 or empty', function () {
    $page = Livewire::test(TrafficSourcesPage::class)->call('load')->call('toggleBlockRule');

    foreach (['', 101, -1, '2.5'] as $refused) {
        $page->set('settings.block_open_floor', $refused)->call('saveSettings')->assertHasErrors(['block_open_floor']);
        expect($page->html())->toMatch('/<input class="[^"]*rg-admin-input--invalid"[^>]*wire:model="settings.block_open_floor"[^>]*aria-invalid="true"/');
    }

    expect(TrafficSetting::current()->exists)->toBeFalse();
    $page->assertSee('The opened below, percent, whatever the source’s average field must be an integer.');

    $page->set('settings.block_open_floor', 10)->call('saveSettings')->assertHasNoErrors()->assertSet('settings.block_open_floor', 10);
    expect(TrafficSetting::current()->block_open_floor)->toBe(10);

    $page->set('settings.block_open_floor', 0)->call('saveSettings')->assertHasNoErrors()->assertSet('settings.block_open_floor', 0);
    expect(TrafficSetting::current()->block_open_floor)->toBe(0);
});

it('calls a site bots below the floor whatever its source’s average, and says so in its badge', function () {
    // A source that is nearly all bots: 4 of its 140 clicks opened the page, 2.9%, half of which would hold nothing.
    siteClicks($this->source, '80001', 100, 3);
    siteClicks($this->source, '80002', 40, 1);
    // A source with a single site is its own average: 2 of 50 opened, 4%.
    $single = TrafficSource::factory()->create(['name' => 'Single-site network', 'block_bot_clicks' => 30]);
    siteClicks($single, '90001', 50, 2);

    $page = Livewire::withQueryParams(['view' => 'sites', 'list' => 'site'])->test(TrafficSourcesPage::class)->call('load');
    $rows = collect($page->viewData('list')['rows'])->keyBy('ref');

    expect($rows->map(fn (array $row): string => $row['state'])->sortKeys()->all())->toBe(['80001' => 'bots', '80002' => 'bots', '90001' => 'bots'])
        ->and($page->viewData('worthBlocking'))->toBe(3)
        ->and($rows['80002']['flag']['tip'])->toBe('Meets the block rule: 2.5% of 40 clicks opened the page, under the rule’s floor of 5%, bots below it always (the source averages 2.9%)')
        ->and($rows['90001']['flag']['tip'])->toBe('Meets the block rule: 4% of 50 clicks opened the page, under the rule’s floor of 5%, bots below it always (the source averages 4%)');

    $page->assertSee('under the rule’s floor of 5%, bots below it always (the source averages 2.9%)');

    // The single-site network sets its own floor at 0: off for it alone, the other source still held to 5%.
    $single->forceFill(['block_open_floor' => 0])->save();
    $rows = collect(Livewire::withQueryParams(['view' => 'sites', 'list' => 'site'])->test(TrafficSourcesPage::class)->call('load')->viewData('list')['rows'])->keyBy('ref');

    expect($rows->map(fn (array $row): string => $row['state'])->sortKeys()->all())->toBe(['80001' => 'bots', '80002' => 'bots', '90001' => 'early']);

    // The general floor off: none of them is bots any more.
    TrafficSetting::current()->forceFill(['block_open_floor' => 0])->save();
    $rows = collect(Livewire::withQueryParams(['view' => 'sites', 'list' => 'site'])->test(TrafficSourcesPage::class)->call('load')->viewData('list')['rows'])->keyBy('ref');

    expect($rows->map(fn (array $row): string => $row['state'])->sortKeys()->all())->toBe(['80001' => 'early', '80002' => 'early', '90001' => 'early']);
});

it('judges Sites & zones by each source’s own thresholds and against its own average, and says why in each badge', function () {
    // Pop traffic opening the page 44 times in 140: half that average, 15.7%, calls a site bots.
    siteClicks($this->source, '50001', 100, 40);
    siteClicks($this->source, '50002', 40, 4);
    // Another network waits for 60 clicks before it calls a site bots.
    $patient = TrafficSource::factory()->create(['name' => 'Patient network', 'block_bot_clicks' => 60]);
    siteClicks($patient, '60001', 40, 0);
    siteClicks($patient, '60002', 40, 40);

    $page = Livewire::withQueryParams(['view' => 'sites', 'list' => 'site'])->test(TrafficSourcesPage::class)->call('load');
    $rows = collect($page->viewData('list')['rows'])->keyBy('ref');

    expect($rows->map(fn (array $row): string => $row['state'])->sortKeys()->all())->toBe(['50001' => 'early', '50002' => 'bots', '60001' => 'early', '60002' => 'early'])
        ->and($page->viewData('worthBlocking'))->toBe(1)
        ->and($rows['50002']['flag']['tip'])->toBe('Meets the block rule: 10% of 40 clicks opened the page, under 15.7%: 50% of the source’s average of 31.4%');

    $page->assertSee('Meets the block rule: 10% of 40 clicks opened the page, under 15.7%: 50% of the source’s average of 31.4%');

    // Judged from 30 clicks too, the other network's site is bots as well: under half its source's 50% average.
    $patient->forceFill(['block_bot_clicks' => 30])->save();
    $page = Livewire::withQueryParams(['view' => 'sites', 'list' => 'site'])->test(TrafficSourcesPage::class)->call('load');

    expect(collect($page->viewData('list')['rows'])->firstWhere('ref', '60001')['state'])->toBe('bots')
        ->and($page->viewData('worthBlocking'))->toBe(2);
});

it('waits days after a site’s first click before calling it losing, and for twice its target price of an account, saying so on the site', function () {
    // The worth stands on 30 accounts.
    $run = TrafficChanceRun::query()->forceCreate(['status' => 'finished', 'reason' => 'nightly', 'price_set_id' => TrafficPriceSet::current()->id, 'matured_before' => now(), 'started_at' => now(), 'finished_at' => now()]);
    TrafficStepChance::query()->forceCreate(['run_id' => $run->id, 'slice_key' => '*', 'step' => 'opened', 'visitors' => 900, 'accounts' => 30, 'slice_accounts' => 30, 'chance' => '0.03333333', 'thin' => false, 'stands_on' => '*']);
    $this->source->forceFill(['target_cpa' => '0.50'])->save();
    // Ten clicks a site, every one opening the page, each worth 30% of its price.
    $site = fn (string $ref, string $firstAt, string $price, string $worth) => TrafficVisit::factory()->count(10)->create([
        'source_id' => $this->source->id, 'site_ref' => $ref, 'arrived_at' => $firstAt, 'network_day' => substr($firstAt, 0, 10), 'opened_at' => $firstAt, 'spend' => $price, 'value_real' => $worth,
    ]);
    $site('70001', '2026-11-12 10:00:00', '0.10', '0.03');
    $site('70002', '2026-11-16 10:00:00', '0.10', '0.03');
    $site('70003', '2026-11-12 10:00:00', '0.08', '0.024');

    $page = Livewire::withQueryParams(['view' => 'sites', 'list' => 'site'])->test(TrafficSourcesPage::class)->call('load');
    $rows = collect($page->viewData('list')['rows'])->keyBy('ref');

    expect($rows->map(fn (array $row): string => $row['state'])->sortKeys()->all())->toBe(['70001' => 'losing', '70002' => 'waiting', '70003' => 'early'])
        ->and($page->viewData('list')['counts'])->toMatchArray(['block' => 1, 'watch' => 1, 'early' => 1])
        ->and($rows['70001']['flag']['tip'])->toBe('Meets the block rule: ROI −70%, below −40%, with $1.00 spent — past 2 × the source’s target price of an account, $0.50, so $1.00')
        ->and($rows['70002']['flag'])->toMatchArray(['label' => 'Judged from 19 Nov', 'tone' => 'warning'])
        ->and($rows['70002']['flag']['tip'])->toBe('ROI −70% is below −40%, but the rule waits 3 days after its first click in the last 30 days, on 16 Nov, so its accounts can arrive: losing is judged from 19 Nov 10:00.')
        ->and($rows['70003']['early'])->toBe('too early: $0.80 of $1.00 spent');

    $page->assertSee('Judged from 19 Nov')->assertSee('too early: $0.80 of $1.00 spent');
});

it('counts a site’s days of waiting from its first click in the rule’s own 30 days, whatever the period shows', function () {
    // The worth stands on 30 accounts.
    $run = TrafficChanceRun::query()->forceCreate(['status' => 'finished', 'reason' => 'nightly', 'price_set_id' => TrafficPriceSet::current()->id, 'matured_before' => now(), 'started_at' => now(), 'finished_at' => now()]);
    TrafficStepChance::query()->forceCreate(['run_id' => $run->id, 'slice_key' => '*', 'step' => 'opened', 'visitors' => 900, 'accounts' => 30, 'slice_accounts' => 30, 'chance' => '0.03333333', 'thin' => false, 'stands_on' => '*']);
    $campaign = TrafficCampaign::factory()->create(['source_id' => $this->source->id]);
    // Ten clicks a day at $0.25, each worth 30% of its price: $2.50 a day, past the rule's $2.
    $clicks = fn (string $ref, string $at) => TrafficVisit::factory()->count(10)->create([
        'source_id' => $this->source->id, 'campaign_id' => $campaign->id, 'site_ref' => $ref, 'arrived_at' => $at, 'network_day' => substr($at, 0, 10), 'opened_at' => $at, 'spend' => '0.25', 'value_real' => '0.075',
    ]);
    // Losing for ten days, and new yesterday.
    $clicks('80001', '2026-11-07 10:00:00');
    $clicks('80001', '2026-11-16 10:00:00');
    $clicks('80002', '2026-11-16 10:00:00');

    $page = Livewire::withQueryParams(['view' => 'sites', 'list' => 'site', 'period' => 'yesterday'])->test(TrafficSourcesPage::class)->call('load');
    $rows = collect($page->viewData('list')['rows'])->keyBy('ref');

    expect($rows->map(fn (array $row): string => $row['state'])->sortKeys()->all())->toBe(['80001' => 'losing', '80002' => 'waiting'])
        ->and($page->viewData('worthBlocking'))->toBe(1)
        ->and($rows['80001']['flag']['label'])->toBe('Losing')
        ->and($rows['80002']['flag']['tip'])->toBe('ROI −70% is below −40%, but the rule waits 3 days after its first click in the last 30 days, on 16 Nov, so its accounts can arrive: losing is judged from 19 Nov 10:00.');

    // Its row in the tree, under its source's campaign, reads the same.
    $page->set('reportView', 'sources')->set('open', ['type=network', 'type=network|source='.$this->source->id, 'type=network|source='.$this->source->id.'|campaign='.$campaign->id]);
    $flags = collect($page->viewData('tree'))->filter(fn (array $row): bool => ($row['flag'] ?? null) !== null)->mapWithKeys(fn (array $row): array => [$row['label'] => $row['flag']['label']])->all();

    expect($flags)->toBe(['site 80001' => 'Losing', 'site 80002' => 'Judged from 19 Nov']);
});

it('narrows every figure by the type of traffic, the game and the device', function () {
    $game = Game::factory()->create(['title' => 'Taste Test']);
    siteClicks($this->source, '80001', 2, 2, ['game_id' => $game->id]);
    // An unpaid visit to another game.
    TrafficVisit::factory()->unpaid(TrafficChannel::Direct)->create(['game_id' => Game::factory(), 'arrived_at' => '2026-11-16 10:00:00', 'network_day' => '2026-11-16', 'opened_at' => now(), 'device' => 'desktop']);

    $visits = fn (array $query): int => Livewire::withQueryParams($query)->test(TrafficSourcesPage::class)->call('load')->viewData('total')->visits;

    expect($visits([]))->toBe(3)
        ->and($visits(['type' => 'unpaid']))->toBe(1)
        ->and($visits(['type' => 'network']))->toBe(2)
        ->and($visits(['game' => (string) $game->id]))->toBe(2)
        ->and($visits(['device' => 'desktop']))->toBe(1);
});

it('shows on the way here the impressions a network billed against those that reached RateGuru, opened the page and started the game, today marked as not closed', function () {
    $campaign = TrafficCampaign::factory()->create(['source_id' => $this->source->id, 'name' => 'US popunder']);
    paidImpressions($this->source, '2026-11-17', 746, '1.38', ['campaign_id' => $campaign->id, 'site_ref' => '40123']);
    reachedVisits($this->source, '2026-11-17', 590, 293, 7, ['campaign_id' => $campaign->id, 'site_ref' => '40123']);

    $page = Livewire::test(TrafficSourcesPage::class)->call('load')
        ->assertSeeInOrder(['Visits', 'On the way here', 'Paid impressions (network)', '746', 'Reached RateGuru', '590', '79%', 'Opened the page', '293', '50%', 'Started the game', '7', '2.4%', 'Mobile and desktop'])
        ->assertSee('Paid, never reached: 156 (21%) · ≈ $0.29 of the network’s $1.38')
        ->assertSee('Includes today, not closed yet: the network’s figures can lag a few minutes behind.');

    expect($page->viewData('reach')['split'])->toBeFalse();

    // Closed by the sync the next day, it is shown plain.
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00'));
    TrafficApiConnection::factory()->create(['source_id' => $this->source->id, 'spend_closed_through' => '2026-11-17']);

    Livewire::test(TrafficSourcesPage::class)->call('load')
        ->assertSee('Paid, never reached: 156 (21%) · ≈ $0.29 of the network’s $1.38')
        ->assertDontSee('not closed yet');
});

it('keeps on the way here to the source, the campaign, the type of traffic and the period on the filters, and leaves the impressions out on a device or a game', function () {
    $campaign = TrafficCampaign::factory()->create(['source_id' => $this->source->id]);
    $other = TrafficCampaign::factory()->create(['source_id' => $this->source->id]);
    paidImpressions($this->source, '2026-11-15', 20, '0.04', ['campaign_id' => $campaign->id, 'site_ref' => '40123']);
    reachedVisits($this->source, '2026-11-15', 12, 6, 2, ['campaign_id' => $campaign->id, 'site_ref' => '40123', 'device' => 'desktop']);
    reachedVisits($this->source, '2026-11-15', 3, 3, 0, ['campaign_id' => $other->id, 'site_ref' => '40123']);
    $elsewhere = TrafficSource::factory()->create(['name' => 'ExoClick tests']);
    // Each address names its period: the page opens on the one last chosen otherwise.
    $page = fn (array $query) => Livewire::withQueryParams(['period' => '30d', ...$query])->test(TrafficSourcesPage::class)->call('load');

    expect($page([])->viewData('reach')['steps'][1]['count'])->toBe(12)
        ->and($page(['source' => (string) $this->source->id])->viewData('reach')['steps'][0]['count'])->toBe(20)
        ->and($page(['campaign' => (string) $campaign->id])->viewData('reach')['steps'][0]['count'])->toBe(20)
        ->and($page(['type' => 'network'])->viewData('reach'))->not->toBeNull()
        ->and($page(['campaign' => (string) $other->id])->viewData('reach'))->toBeNull()
        ->and($page(['source' => (string) $elsewhere->id])->viewData('reach'))->toBeNull()
        ->and($page(['type' => 'unpaid'])->viewData('reach'))->toBeNull()
        ->and($page(['type' => 'placement'])->viewData('reach'))->toBeNull()
        ->and($page(['period' => 'yesterday'])->viewData('reach'))->toBeNull();

    // Narrowed to a device, only the networks' visits on the filters show: the network's impressions aren't split by device.
    $page(['device' => 'desktop'])
        ->assertSee('On the way here')
        ->assertSee('The network’s impressions aren’t split by device, country or game, so they’re left out on these filters.')
        ->assertSeeInOrder(['Visits from ad networks', '12', 'Opened the page', '6', '50%', 'Started the game', '2', '33%'])
        ->assertDontSee('Paid impressions (network)')
        ->assertDontSee('Paid, never reached');

    expect($page(['game' => (string) Game::factory()->create()->id])->viewData('reach')['split'])->toBeTrue();
});

it('shows in Sites & zones each site’s paid impressions and the share that reached RateGuru, sortable, and hints at one that loses far more than its source over closed days', function () {
    config(['traffic.reach.min_impressions' => 50, 'traffic.reach.below_percent_of_source' => 70]);
    $campaign = TrafficCampaign::factory()->create(['source_id' => $this->source->id]);
    // Yesterday, closed: the source reached 240 of 300, 80%; 70% of it is 56%.
    foreach (['70001' => [50, 27], '70002' => [50, 28], '70003' => [49, 5], '70004' => [151, 180]] as $site => [$impressions, $reached]) {
        paidImpressions($this->source, '2026-11-16', $impressions, '0.10', ['campaign_id' => $campaign->id, 'site_ref' => (string) $site]);
        reachedVisits($this->source, '2026-11-16', $reached, $reached, 0, ['campaign_id' => $campaign->id, 'site_ref' => (string) $site]);
    }
    // Today, not closed: it would lift 70001 above the line, but only closed days count for the hint.
    paidImpressions($this->source, '2026-11-17', 20, '0.04', ['campaign_id' => $campaign->id, 'site_ref' => '70001']);
    reachedVisits($this->source, '2026-11-17', 20, 20, 0, ['campaign_id' => $campaign->id, 'site_ref' => '70001']);
    // A site the network reported no impressions for.
    reachedVisits($this->source, '2026-11-16', 3, 3, 0, ['campaign_id' => $campaign->id, 'site_ref' => '70005']);

    $page = Livewire::withQueryParams(['view' => 'sites', 'list' => 'site'])->test(TrafficSourcesPage::class)->call('load');
    $rows = collect($page->viewData('list')['rows'])->keyBy('ref');

    expect($rows['70001']['state'])->toBe('low_reach')
        ->and($rows['70001']['flag'])->toBe(['label' => 'Low reach', 'tone' => 'warning', 'tip' => 'Paid impressions mostly never reach RateGuru: 54% reached against 80% for the source, over 50 paid impressions of closed days'])
        ->and([$rows['70001']['reach']->impressions, $rows['70001']['reach']->reached])->toBe([70, 47])
        ->and($rows['70002']['state'])->toBe('early')
        ->and($rows['70003']['state'])->toBe('early')
        ->and($rows['70005']['reach'])->toBeNull()
        ->and($page->viewData('list')['counts']['block'])->toBe(1)
        ->and($page->viewData('worthBlocking'))->toBe(1)
        ->and(Livewire::test(TrafficSourcesPage::class)->call('load')->viewData('worthBlocking'))->toBe(1);

    $page->assertSeeInOrder(['Paid impr.', 'Reached', 'Visits'])
        ->assertSee('Low reach')
        ->assertSee('67.1%')
        ->call('toggleBlockRule')
        ->assertSeeText('Low reach: at least 50 paid impressions on days the network has closed, and fewer of them reached RateGuru than 70% of the source’s share — for networks that bill by the impression. Shown, never blocked by itself.')
        ->call('toggleBlockRule')
        ->call('sortBy', 'reach')
        ->assertSet('sort', 'reach');

    expect(collect($page->viewData('list')['rows'])->pluck('ref')->all())->toBe(['70004', '70001', '70002', '70003', '70005']);

    $page->call('sortBy', 'reach')->assertSet('dir', 'asc');

    expect(collect($page->viewData('list')['rows'])->pluck('ref')->all())->toBe(['70003', '70002', '70001', '70004', '70005']);

    $page->call('sortBy', 'impressions');

    expect(collect($page->viewData('list')['rows'])->pluck('ref')->all())->toBe(['70004', '70001', '70002', '70003', '70005']);

    // One impression fewer than asked, and the site is no longer judged on them.
    config(['traffic.reach.min_impressions' => 51]);

    expect(collect(Livewire::withQueryParams(['view' => 'sites', 'list' => 'site'])->test(TrafficSourcesPage::class)->call('load')->viewData('list')['rows'])->keyBy('ref')['70001']['state'])->toBe('early');

    // On a device the network has no impressions to set beside the visits: dashes, and no hint.
    $mobile = collect(Livewire::withQueryParams(['view' => 'sites', 'list' => 'site', 'device' => 'mobile'])->test(TrafficSourcesPage::class)->call('load')->viewData('list')['rows'])->keyBy('ref');

    expect($mobile['70001']['reach'])->toBeNull()
        ->and($mobile['70001']['state'])->toBe('early');
});

it('keeps a verdict, a losing site’s wait and a profit its spend already paid the lost impressions for over Low reach, which stands in for the rest', function () {
    config(['traffic.reach.min_impressions' => 50, 'traffic.reach.below_percent_of_source' => 70]);
    // The worth stands on 30 accounts.
    $run = TrafficChanceRun::query()->forceCreate(['status' => 'finished', 'reason' => 'nightly', 'price_set_id' => TrafficPriceSet::current()->id, 'matured_before' => now(), 'started_at' => now(), 'finished_at' => now()]);
    TrafficStepChance::query()->forceCreate(['run_id' => $run->id, 'slice_key' => '*', 'step' => 'opened', 'visitors' => 900, 'accounts' => 30, 'slice_accounts' => 30, 'chance' => '0.03333333', 'thin' => false, 'stands_on' => '*']);
    // Ten clicks are enough here for bots and for a weak start.
    $this->source->forceFill(['block_bot_clicks' => 10, 'block_start_clicks' => 10])->save();
    // A sound site: all 100 paid impressions arrived, so the source reached 180 of 900, 20%; 70% of it is 14%.
    paidImpressions($this->source, '2026-11-15', 100, '0.20', ['site_ref' => '70000']);
    reachedVisits($this->source, '2026-11-15', 100, 100, 100, ['site_ref' => '70000']);
    // Each other site: 10 of 100 paid impressions arrived, 10%, on a closed day.
    $site = function (string $ref, string $day, int $opened, int $started, string $spend, string $worth, bool $counted = false): void {
        paidImpressions($this->source, $day, 100, '0.20', ['site_ref' => $ref, 'counted' => $counted]);
        reachedVisits($this->source, $day, 10, $opened, $started, ['site_ref' => $ref, 'spend' => $spend, 'value_real' => $worth]);
    };
    $site('70001', '2026-11-16', 0, 0, '0.01', '0');
    $site('70002', '2026-11-12', 10, 10, '0.25', '0.075');
    $site('70003', '2026-11-16', 10, 10, '0.25', '0.075');
    $site('70004', '2026-11-16', 10, 0, '0.01', '0');
    // Profitable: its spend shared out from the network's figure, which paid for the lost impressions too — or from the cost in each click, which never did.
    $site('70005', '2026-11-16', 10, 10, '0.25', '0.30', counted: true);
    $site('70006', '2026-11-16', 10, 10, '0.25', '0.30');
    $site('70007', '2026-11-16', 10, 10, '0.01', '0');
    $site('70008', '2026-11-16', 10, 10, '0.25', '0.20');

    $page = Livewire::withQueryParams(['view' => 'sites', 'list' => 'site'])->test(TrafficSourcesPage::class)->call('load');
    $rows = collect($page->viewData('list')['rows'])->keyBy('ref');

    expect($rows->map(fn (array $row): string => $row['state'])->sortKeys()->all())->toBe([
        '70000' => 'early',
        '70001' => 'bots',
        '70002' => 'losing',
        '70003' => 'waiting',
        '70004' => 'weak_start',
        '70005' => 'profit',
        '70006' => 'low_reach',
        '70007' => 'low_reach',
        '70008' => 'low_reach',
    ])
        ->and($rows->map(fn (array $row): ?string => $row['flag']['label'] ?? null)->sortKeys()->only(['70001', '70002', '70003', '70004', '70005', '70006'])->all())->toBe([
            '70001' => 'Likely bots',
            '70002' => 'Losing',
            '70003' => 'Judged from 19 Nov',
            '70004' => 'Weak start',
            '70005' => 'Profitable',
            '70006' => 'Low reach',
        ])
        ->and($rows['70006']['flag']['tip'])->toBe('Paid impressions mostly never reach RateGuru: 10% reached against 20% for the source, over 100 paid impressions of closed days')
        ->and($page->viewData('list')['counts'])->toMatchArray(['block' => 6, 'profit' => 1, 'watch' => 1, 'early' => 1])
        ->and($page->viewData('worthBlocking'))->toBe(6);
});

it('shows on Spend each day’s impressions and how many of them reached RateGuru', function () {
    $campaign = TrafficCampaign::factory()->create(['source_id' => $this->source->id]);
    paidImpressions($this->source, '2026-11-16', 40, '0.08', ['campaign_id' => $campaign->id, 'site_ref' => '40123']);
    reachedVisits($this->source, '2026-11-16', 30, 10, 1, ['campaign_id' => $campaign->id, 'site_ref' => '40123']);
    reachedVisits($this->source, '2026-11-15', 5, 5, 0, ['campaign_id' => $campaign->id, 'site_ref' => '40123']);

    $page = Livewire::withQueryParams(['tab' => 'spend'])->test(TrafficSourcesPage::class)->call('load')
        ->set('spendSource', (string) $this->source->id)
        ->assertSeeInOrder(['Day', 'Clicks', 'Impressions', 'Reached', 'From click costs', 'From the network', 'In reports'])
        ->assertSeeInOrder(['Yesterday', '30', '40', '30', '75.0%']);
    $days = collect($page->viewData('dayRows'))->keyBy('date');

    expect($days['2026-11-16']['reach']->impressions)->toBe(40)
        ->and($days['2026-11-15']['reach'])->toBeNull();
});

it('lays out Campaigns: the new campaign to set up, the link with samples, the latest clicks, campaigns by stage, and banners against their price', function () {
    $network = TrafficSource::factory()->create(['name' => 'ExoClick tests', 'link_key' => 'exo']);
    $banner = TrafficSource::factory()->create(['name' => 'Cat blog sidebar', 'type' => 'placement', 'link_key' => 'cat', 'spend_mode' => 'fixed', 'fixed_amount' => '120', 'fixed_period' => 'month', 'fixed_from' => '2026-11-01']);
    $new = TrafficCampaign::factory()->create(['source_id' => $this->source->id, 'external_id' => '881377', 'status' => 'new']);
    $scaling = TrafficCampaign::factory()->create(['source_id' => $network->id, 'external_id' => '5521904', 'name' => 'TYT push', 'status' => 'active', 'stage' => 'scale', 'countries' => ['DE', 'AT'], 'bid_model' => 'click', 'bid_mobile' => '0.01', 'bid_desktop' => '0.017']);
    siteClicks($network, '47494', 2, 2, ['campaign_id' => $scaling->id]);
    siteClicks($banner, '', 1, 1, ['channel' => 'placement', 'spend' => '60']);
    Game::factory()->create(['title' => 'Taste Test']);

    $page = Livewire::withQueryParams(['tab' => 'campaigns'])->test(TrafficSourcesPage::class)->call('load')
        ->assertSee('PopAds main campaign 881377 is new')
        ->assertSee('How a campaign gets here')
        ->assertSee('A real ExoClick tests click')
        ->assertSee('Latest clicks')
        ->assertSee('rg_src=exo')
        ->assertSee('Germany, Austria')
        ->assertSee('$0.0100 / $0.0170')
        ->assertSee('Banners & links')
        ->assertSee('Cat blog sidebar')
        // A campaign's row is a table row whose name is the button that opens it, never a button standing in for the row.
        ->assertSeeHtml('aria-label="Open campaign TYT push"')
        ->assertDontSeeHtml('<button type="button" class="rg-admin-traffic__camp-row');

    $page->set('stage', 'scale')->assertSee('TYT push')->assertDontSee('New campaign 881377')
        ->set('stage', 'new')->assertSee('New campaign 881377')
        ->call('openCampaign', $new->id)->assertSet('camp', (string) $new->id)
        // A network's campaign, its type and its sites ticked: none of it the banner's, so its report drops them.
        ->set('campaignId', (string) $scaling->id)->set('type', 'network')->set('selected', [$network->id.':site:47494'])
        ->call('reportFor', $banner->id)->assertSet('tab', 'report')->assertSet('source', (string) $banner->id)
        ->assertSet('campaignId', '')->assertSet('type', '')->assertSet('selected', []);
});

it('marks the source and the game of the link Campaigns shows as chosen in their pickers', function () {
    $exo = TrafficSource::factory()->create(['name' => 'ExoClick tests', 'link_key' => 'exo']);
    $first = Game::factory()->create(['title' => 'A taste test']);
    $second = Game::factory()->create(['title' => 'Trust Your Taste']);

    // The link starts on the first network by name and the first game by title.
    $page = Livewire::withQueryParams(['tab' => 'campaigns'])->test(TrafficSourcesPage::class)->call('load')->assertSee('rg_src=exo');
    $pickers = Str::betweenFirst($page->html(), 'rg-admin-traffic__link-pickers', '</div>');
    // Each picker on its own: a source and a game may share an id.
    $sources = Str::betweenFirst($pickers, 'aria-label="Source"', '</select>');
    $games = Str::betweenFirst($pickers, 'aria-label="Game"', '</select>');

    expect($sources)->toContain('<option value="'.$exo->id.'" selected>')
        ->not->toContain('<option value="'.$this->source->id.'" selected>')
        ->and($games)->toContain('<option value="'.$first->id.'" selected>')
        ->not->toContain('<option value="'.$second->id.'" selected>');
});

it('asks Campaigns for a game, not another source, when there is a source but no game to link to', function () {
    Livewire::withQueryParams(['tab' => 'campaigns'])->test(TrafficSourcesPage::class)->call('load')
        ->assertSee('Add a game to get its link: every link opens one game.')
        ->assertDontSee('Add a network or a banner under Sources');
});

it('lays out Value: outcome prices with what an account brings, accounts by source against a target, who counts, and country groups with their countries', function () {
    // Four accounts, each worth an account at a full share: what they brought is measured, not assumed.
    siteClicks($this->source, '40123', 4, 4, ['account_at' => now(), 'spend' => '0.25', 'value_real' => '0.5']);
    // Two sign-ins to accounts the visitors already had: worth their price, but no part of what an account brings.
    siteClicks($this->source, '40123', 2, 2, ['known' => true, 'known_reason' => TrafficKnownReason::Login, 'login_at' => now(), 'value_real' => '0.3']);

    $page = Livewire::withQueryParams(['tab' => 'value'])->test(TrafficSourcesPage::class)->call('load')
        ->assertSet('prices.account_price', '0.50')
        ->assertSet('prices.login_price', '0.00')
        ->assertSee('What a new user is worth')
        ->assertSeeInOrder(['Existing account signed in', 'signed in after the click', '2 sign-ins'])
        ->assertSee('An account brings on average')
        ->assertViewHas('accountBrings', '0.50000000')
        ->assertViewHas('outcomes', fn (array $outcomes): bool => $outcomes['logins'] === 2)
        ->assertSee('Accounts by source')
        ->assertSee('PopAds main')
        ->assertSee('below its worth')
        ->assertSee('Who counts')
        ->assertSee('A new visitor')
        ->assertSee('Price changes');

    $page->set('targets.'.$this->source->id, '0.20')->call('saveTarget', $this->source->id)->assertSee('over target');

    expect($this->source->fresh()->target_cpa)->toBe('0.2000');

    $page->call('addCountry', 0, 'br')->call('removeCountry', 0, 'US');

    expect($page->get('groups')[0]['countries'])->toContain('BR')->not->toContain('US')
        ->and(collect($page->get('groups'))->filter(fn (array $group): bool => str_contains((string) $group['countries'], 'BR'))->count())->toBe(1);
});

it('lays out Spend: where spend comes from, spend by source by campaign — those whose spend found no visit too — and a source day by day, with a day corrected', function () {
    $this->source->forceFill(['spend_mode' => 'click_cost'])->save();
    $campaign = TrafficCampaign::factory()->create(['source_id' => $this->source->id, 'external_id' => '881204', 'name' => 'US popunder']);
    siteClicks($this->source, '40123', 3, 3, ['campaign_id' => $campaign->id, 'cost' => '0.01']);
    $banner = TrafficSource::factory()->create(['name' => 'Cat blog sidebar', 'type' => 'placement', 'link_key' => 'cat', 'spend_mode' => 'fixed', 'fixed_amount' => '30', 'fixed_period' => 'month', 'fixed_from' => '2026-11-01']);
    TrafficSpendDay::query()->forceCreate(['source_id' => $banner->id, 'network_day' => '2026-11-16', 'origin' => 'fixed', 'site_ref' => '', 'zone_ref' => '', 'amount' => '1.00', 'counted' => true, 'allocated_amount' => 0, 'allocated_at' => now()]);

    $page = Livewire::withQueryParams(['tab' => 'spend'])->test(TrafficSourcesPage::class)->call('load')
        ->set('spendSource', (string) $this->source->id)
        ->assertSee('Where spend comes from')
        ->assertSee('Used by PopAds main')
        ->assertSee('Price in each click · per click')
        ->assertSee('Cat blog sidebar')
        ->assertSee('$1.00 without visits')
        ->assertSee('Day by day')
        ->assertSee('Yesterday')
        ->call('toggleSpendSource', $this->source->id)
        ->assertSee('881204');

    $page->set('spendEntry.day', '2026-11-16')->set('spendEntry.amount', '5')->call('enterSpend')->assertHasNoErrors();

    expect(TrafficSpendDay::query()->where('source_id', $this->source->id)->where('origin', 'manual')->sole()->amount)->toBe('5.00000000');
});

it('imports a spend file into the source it was chosen for, whichever source Spend shows by the time it has uploaded', function () {
    $other = TrafficSource::factory()->create(['name' => 'ExoClick tests', 'link_key' => 'exo']);
    app(EnterSpendAction::class)->file(auth()->user(), $this->source, 'popads.csv', "day,amount\n2026-11-14,10\n");
    app(EnterSpendAction::class)->file(auth()->user(), $other, 'exoclick.csv', "day,amount\n2026-11-14,20\n");
    $amounts = fn (TrafficSource $source): array => TrafficSpendDay::query()->where('source_id', $source->id)->where('origin', 'csv')->pluck('amount')->all();

    // The file was chosen with PopAds main on screen, and ExoClick tests picked while it was still uploading.
    Livewire::withQueryParams(['tab' => 'spend'])->test(TrafficSourcesPage::class)->call('load')
        ->set('spendSource', (string) $other->id)
        ->set('spendFiles.'.$this->source->id, UploadedFile::fake()->createWithContent('popads-again.csv', "day,amount\n2026-11-14,7\n"))
        ->assertHasNoErrors()
        ->assertSet('spendFiles', [])
        ->assertDispatched('rg-admin-toast', message: '1 figures imported');

    expect($amounts($this->source))->toBe(['7.00000000'])
        ->and($amounts($other))->toBe(['20.00000000']);
});

it('lays out Sources: networks and banners with their postbacks, spend and clicks, then the unpaid channels', function () {
    TrafficPostbackRule::factory()->create(['source_id' => $this->source->id, 'event' => 'goal']);
    TrafficSource::factory()->create(['name' => 'Cat blog sidebar', 'type' => 'placement', 'link_key' => 'cat', 'spend_mode' => 'fixed', 'fixed_amount' => '120', 'fixed_period' => 'month', 'fixed_from' => '2026-11-01']);
    siteClicks($this->source, '40123', 3, 3);
    TrafficVisit::factory()->unpaid(TrafficChannel::Search)->create(['game_id' => Game::factory(), 'arrived_at' => '2026-11-16 10:00:00', 'network_day' => '2026-11-16', 'opened_at' => now()]);

    Livewire::withQueryParams(['tab' => 'sources'])->test(TrafficSourcesPage::class)->call('load')
        ->assertSeeInOrder(['AD NETWORKS', 'PopAds main', '1 postback', 'delivering', 'Flow goal reached', '3 clicks', 'BANNERS', 'Cat blog sidebar', 'No postbacks', 'UNPAID', 'Search', '1 visits'])
        ->assertSee('How a postback works')
        ->assertSee('Tracking');
});

it('lays out Logs: postbacks first, with the milestone, the click, the exact request, the answer and the path, failed ones only on asking', function () {
    $visit = TrafficVisit::factory()->create(['source_id' => $this->source->id, 'arrived_at' => now()->subHour(), 'opened_at' => now()->subHour(), 'signup_at' => now()->subMinutes(55), 'account_at' => now()->subMinutes(50), 'signup_place' => 'game', 'signup_after_seconds' => 300]);
    TrafficVisitDetail::query()->forceCreate(['visit_id' => $visit->id, 'click_id' => 'tag-81442', 'landing_path' => '/games/x', 'created_at' => now()]);
    $conversion = TrafficConversion::factory()->create(['visit_id' => $visit->id, 'event' => 'account', 'source_id' => $this->source->id]);
    TrafficPostback::factory()->create(['conversion_id' => $conversion->id, 'source_id' => $this->source->id, 'event' => 'account', 'url' => 'https://pb.example.com/c?id={click_id}', 'status' => 'failed', 'http_status' => 503, 'attempts' => 6]);

    Livewire::withQueryParams(['tab' => 'logs'])->test(TrafficSourcesPage::class)->call('load')
        ->assertSee('1 failing')
        ->assertSee('Signed up in the game')
        ->assertSee('5 min after the click')
        ->assertSee('tag-81442')
        ->assertSee('GET https://pb.example.com/c?id=tag-81442')
        ->assertSee('HTTP 503')
        ->assertSee('Show the path')
        ->assertSee('Resend')
        ->set('logFilter', 'failed')->assertSee('tag-81442')
        ->set('log', 'clicks')->assertSee('/games/x');
});

it('leads an empty later page of a log back to the newest entries, as an address kept from before may open one', function () {
    $milestone = TrafficConversion::factory()->create(['source_id' => $this->source->id]);

    Livewire::withQueryParams(['tab' => 'logs', 'log' => 'milestones', 'page' => 5])->test(TrafficSourcesPage::class)->call('load')
        ->assertSet('pageNumber', 5)
        ->assertSee('Nothing on this page')
        ->assertSeeHtml('wire:click="goToPage(1)"')
        ->assertDontSee('Nothing in this log yet')
        ->call('goToPage', 1)
        ->assertDontSee('Nothing on this page')
        ->assertSeeHtml('wire:key="milestone-'.$milestone->id.'"');
});

/** A paid click by the browser given (a name, hashed) that reached a confirmed account, its milestone and the postback sent for it. */
function loggedVisitor(TrafficSource $source, string $browser = 'log-visitor'): TrafficVisit
{
    $visit = TrafficVisit::factory()->create(['source_id' => $source->id, 'visitor_hash' => hash('sha256', $browser), 'arrived_at' => now()->subHour(), 'opened_at' => now()->subHour(), 'account_at' => now()->subMinutes(50)]);
    TrafficVisitDetail::query()->forceCreate(['visit_id' => $visit->id, 'click_id' => 'tag-'.$visit->id, 'landing_path' => '/games/x', 'created_at' => now()]);
    $conversion = TrafficConversion::factory()->create(['visit_id' => $visit->id, 'event' => 'account', 'source_id' => $source->id]);
    TrafficPostback::factory()->create(['conversion_id' => $conversion->id, 'source_id' => $source->id, 'event' => 'account']);

    return $visit;
}

it('names the visitor of each click, milestone and postback by its journey ID, a link to its journey in Analytics', function () {
    $hash = loggedVisitor($this->source)->visitor_hash;
    $id = JourneyTimeline::visitorId($hash);
    $link = 'href="'.e(GrowthAnalyticsPage::getUrl(['tab' => 'journeys', 'who' => JourneyTimeline::visitorId($hash, JourneyTimeline::LONG_DIGITS)])).'"';

    // A postback's visitor in its row, and again where its path ends.
    $page = Livewire::withQueryParams(['tab' => 'logs'])->test(TrafficSourcesPage::class)->call('load')
        ->assertSeeInOrder(['Source', 'Visitor', 'Milestone', 'PopAds main', $id, 'Show the path', 'Journey', $id])
        ->assertSeeHtml($link);

    expect(substr_count($page->html(), $link))->toBe(2);

    foreach (['milestones' => 'Milestone', 'clicks' => 'Landing'] as $log => $next) {
        $page->set('log', $log)->assertSeeInOrder(['Source', 'Visitor', $next, 'PopAds main', $id]);

        expect(substr_count($page->html(), $link))->toBe(1, $log);
    }

    // Blocking has no visitor.
    $page->set('log', 'blocks')->assertDontSee('Visitor');
});

it('reads the visitors of a log’s page at once, never one row at a time', function () {
    $queries = function (string $log): int {
        $page = Livewire::withQueryParams(['tab' => 'logs', 'log' => $log])->test(TrafficSourcesPage::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $page->call('load');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };
    $logs = ['postbacks', 'milestones', 'clicks'];

    loggedVisitor($this->source, 'first');
    $few = array_map($queries, $logs);

    foreach (range(1, 5) as $n) {
        loggedVisitor($this->source, 'more-'.$n);
    }

    expect(array_map($queries, $logs))->toBe($few);
});

it('shows the visitor’s ID without the link to an administrator who may not open Analytics', function () {
    $id = JourneyTimeline::visitorId(loggedVisitor($this->source)->visitor_hash);
    // Traffic sources and Analytics share their gate, so nobody sees the logs without Analytics; the link asks Analytics' own all the same.
    $this->actingAs(User::factory()->admin()->limited()->create());

    expect(GrowthAnalyticsPage::canAccess())->toBeFalse();

    foreach (['postbacks', 'milestones', 'clicks'] as $log) {
        $page = new TrafficSourcesPage;
        [$page->tab, $page->log, $page->ready] = ['logs', $log, true];

        $this->view('filament.pages.traffic-sources.logs', [...app(TrafficSourcesView::class)->data($page, $page->filter()), 'log' => $log, 'logFilter' => 'all', 'pageNumber' => 1])
            ->assertSee($id)
            ->assertDontSee('who=', false)
            ->assertDontSee('traffic-log-journey', false);
    }
});

it('opens the visitor’s own journey from the log’s link, even where another browser shares its ID, the lookup written down as one from an address', function () {
    // Another browser whose ID — the first 8 digits — is the visitor's own, recorded first so the visitor's row leads the log.
    $hash = hash('sha256', 'log-visitor');
    TrafficVisit::factory()->create(['source_id' => $this->source->id, 'visitor_hash' => substr($hash, 0, JourneyTimeline::VISITOR_DIGITS).str_repeat('0', 64 - JourneyTimeline::VISITOR_DIGITS)]);
    loggedVisitor($this->source);
    [$id, $who] = [JourneyTimeline::visitorId($hash), JourneyTimeline::visitorId($hash, JourneyTimeline::LONG_DIGITS)];
    $html = Livewire::withQueryParams(['tab' => 'logs', 'log' => 'clicks'])->test(TrafficSourcesPage::class)->call('load')->html();

    expect(preg_match('/<a href="([^"]+)"[^>]*data-testid="traffic-log-journey"[^>]*>\s*'.$id.'\s*</', $html, $match))->toBe(1);

    $url = html_entity_decode($match[1]);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect(parse_url($url, PHP_URL_PATH))->toBe(parse_url(GrowthAnalyticsPage::getUrl(), PHP_URL_PATH))
        ->and($query)->toBe(['tab' => 'journeys', 'who' => $who]);

    // The ID the row shows names both browsers, so it alone would ask which one.
    Livewire::withQueryParams(['tab' => 'journeys', 'who' => $id])->test(GrowthAnalyticsPage::class)->call('load')
        ->assertViewHas('data', fn (array $data): bool => $data['state'] === 'matches');

    Log::spy();
    $this->get($url)->assertOk();

    // The journey as the page reads it once it is in a browser: the visitor itself, not the first example or a list to choose from.
    Livewire::withQueryParams($query)->test(GrowthAnalyticsPage::class)->call('load')
        ->assertSet('tab', 'journeys')
        ->assertSet('who', $who)
        ->assertViewHas('data', fn (array $data): bool => $data['state'] === 'journey' && $data['journey']['label'] === $id.' · not registered');

    Log::shouldHaveReceived('info')->withArgs(fn (string $event, array $context): bool => $event === 'analytics.journey.lookup' && $context['journey'] === $who && $context['via'] === 'address')->once();
});

it('shows sign-ups and confirmation by the dimension chosen, ten rows and the rest in one, with the paid part How accounts come counts', function () {
    $signedUp = ['arrived_at' => '2026-11-16 10:00:00', 'network_day' => '2026-11-16', 'signup_at' => '2026-11-16 10:05:00', 'signup_method' => 'email'];

    // Twelve zones of the network, zone 700nn signing up nn visitors, every one confirmed within ten minutes.
    foreach (range(1, 12) as $zone) {
        TrafficVisit::factory()->count($zone)->create(['source_id' => $this->source->id, 'zone_ref' => (string) (70000 + $zone), ...$signedUp, 'account_at' => '2026-11-16 10:08:00', 'signup_after_seconds' => 300, 'account_after_seconds' => 480]);
    }
    // Two email sign-ups more than a week ago that never confirmed, and one of yesterday still waiting.
    TrafficVisit::factory()->count(2)->create(['source_id' => $this->source->id, 'zone_ref' => '70001', ...$signedUp, 'arrived_at' => '2026-11-08 10:00:00', 'network_day' => '2026-11-08', 'signup_at' => '2026-11-08 10:05:00']);
    TrafficVisit::factory()->create(['source_id' => $this->source->id, 'zone_ref' => '70001', ...$signedUp]);
    // Unpaid sign-ups: How accounts come leaves them out, the card does not.
    TrafficVisit::factory()->unpaid(TrafficChannel::Direct)->count(13)->create([...$signedUp, 'game_id' => Game::factory()->create()->id, 'signup_method' => 'google', 'account_at' => '2026-11-16 10:05:00', 'signup_after_seconds' => 300, 'account_after_seconds' => 300]);

    $page = Livewire::test(TrafficSourcesPage::class)->call('load')
        ->assertSee('Sign-ups and confirmation')
        ->assertSeeInOrder(['How accounts come', 'Never confirmed: signed up over 7 days ago, removed, never counted', '2', 'Still waiting: signed up in the last 7 days, not confirmed yet', '1'])
        ->assertSeeInOrder(['Sign-ups and confirmation', 'PopAds main', 'Ad network', 'Direct', 'Unpaid', 'Total', 'Of it paid traffic', 'What How accounts come counts']);
    $card = $page->viewData('signups');

    // The card's total is every type of traffic; its paid part is How accounts come's own figures.
    expect($card['total'])->signedUp->toBe(94)->confirmed->toBe(91)->neverConfirmed->toBe(2)->waiting->toBe(1)
        ->and($card['paid'])->toEqual($page->viewData('paidSignups'))
        ->and($card['paid'])->signedUp->toBe(81)->confirmed->toBe(78)
        ->and(collect($card['rows'])->pluck('label')->all())->toBe(['PopAds main', 'Direct']);

    $page->call('$set', 'signupsBy', 'zone')->assertSet('signupsBy', 'zone')
        ->assertSee('zone 70012')
        ->assertSee('+ 3 more zones')
        ->assertSee('Find in Sites &amp; zones', false);
    $card = $page->viewData('signups');

    // The ten rows with the most sign-ups — unpaid traffic has no zone, so it is one — then the rest in one row, adding up to the total.
    expect(collect($card['rows'])->pluck('label')->all())->toBe(['Unpaid traffic', 'zone 70012', 'zone 70011', 'zone 70010', 'zone 70009', 'zone 70008', 'zone 70007', 'zone 70006', 'zone 70005', 'zone 70004'])
        ->and($card['rows'][0]['find'])->toBeNull()
        ->and($card['rows'][1])->toMatchArray(['sub' => 'PopAds main', 'mono' => true, 'find' => ['source' => $this->source->id, 'level' => 'zone', 'ref' => '70012']])
        ->and($card['rest']['count'])->toBe(3)
        ->and($card['rest']['figures'])->signedUp->toBe(4 + 3 + 2)->confirmed->toBe(1 + 3 + 2)->neverConfirmed->toBe(2)->waiting->toBe(1)
        ->and(collect($card['rows'])->reduce(fn ($sum, array $row) => $sum->plus($row['figures']), $card['rest']['figures']))->toEqual($card['total']);

    // A zone's row opens it in Sites & zones, where it is blocked — shown however few clicks it has.
    $page->set('minClicks', 100)
        ->call('listSites', $this->source->id, 'zone', '70012')
        ->assertSet('reportView', 'sites')
        ->assertSet('listBy', 'zone')
        ->assertSet('q', '70012')
        ->assertSet('minClicks', 0);
});

it('keeps the sign-ups dimension in the address, and splits by method and by where they signed up', function () {
    TrafficVisit::factory()->create(['source_id' => $this->source->id, 'arrived_at' => '2026-11-16 10:00:00', 'network_day' => '2026-11-16', 'signup_at' => '2026-11-16 10:05:00', 'signup_method' => 'facebook', 'signup_place' => 'feed', 'account_at' => '2026-11-16 10:05:00']);

    $labels = fn (array $query): array => collect(Livewire::withQueryParams($query)->test(TrafficSourcesPage::class)->call('load')->viewData('signups')['rows'])->pluck('label')->all();

    expect($labels(['signups' => 'signup_method']))->toBe(['Facebook'])
        ->and($labels(['signups' => 'signup_place']))->toBe(['In the feed'])
        ->and($labels(['signups' => 'device']))->toBe(['Mobile'])
        ->and($labels(['signups' => 'campaign']))->toBe(['No campaign']);

    Livewire::withQueryParams(['signups' => 'nonsense'])->test(TrafficSourcesPage::class)->assertSet('signupsBy', 'source');
});

it('says so when nobody signed up on the filters', function () {
    siteClicks($this->source, '40123', 3, 3);

    Livewire::test(TrafficSourcesPage::class)->call('load')
        ->assertSee('No sign-ups on these filters')
        ->assertDontSee('data-testid="traffic-signups-paid"', false);
});
