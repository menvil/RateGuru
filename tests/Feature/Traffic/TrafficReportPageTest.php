<?php

use App\Actions\Traffic\BlockTrafficRefsAction;
use App\Actions\Traffic\EnterSpendAction;
use App\Enums\TrafficActor;
use App\Enums\TrafficBlockLevel;
use App\Enums\TrafficBlockMethod;
use App\Enums\TrafficBlockNetworkState;
use App\Enums\TrafficChannel;
use App\Enums\TrafficConnector;
use App\Enums\TrafficKnownReason;
use App\Enums\UserRole;
use App\Filament\Pages\GrowthAnalyticsPage;
use App\Filament\Pages\TrafficSources\TrafficSourcesView;
use App\Filament\Pages\TrafficSourcesPage;
use App\Models\Game;
use App\Models\TrafficApiConnection;
use App\Models\TrafficBlock;
use App\Models\TrafficBlockEvent;
use App\Models\TrafficBlockListing;
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
use App\Support\Traffic\Capture\BotSignals;
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

it('opens no row at first, the ad networks to their sources and a source to its campaigns when asked', function () {
    $campaign = TrafficCampaign::factory()->create(['source_id' => $this->source->id, 'name' => 'US popunder']);
    siteClicks($this->source, '40123', 3, 3, ['campaign_id' => $campaign->id]);
    TrafficVisit::factory()->unpaid(TrafficChannel::Search)->create(['game_id' => Game::factory(), 'arrived_at' => '2026-11-16 10:00:00', 'network_day' => '2026-11-16', 'opened_at' => now()]);

    $labels = fn ($page): array => collect($page->viewData('tree'))->pluck('label')->filter()->values()->all();
    $page = Livewire::test(TrafficSourcesPage::class)->call('load')
        ->assertSeeInOrder(['Ad networks', 'Unpaid', '1 channel', 'costs nothing', 'Total']);

    expect($labels($page))->toBe(['Ad networks', 'Unpaid']);

    $page->call('toggleNode', 'type=network');

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

it('shows in Sites & zones how many of each place’s visits carried a sign of a bot, and came from an address it sent another from, sortable by both', function () {
    siteClicks($this->source, '70001', 4, 4, ['bot_flags' => BotSignals::NO_BROWSER_HEADERS]);
    siteClicks($this->source, '70002', 4, 4);
    TrafficVisit::query()->where('site_ref', '70002')->get()->each(fn (TrafficVisit $visit) => TrafficVisitDetail::query()->forceCreate(['visit_id' => $visit->id, 'landing_path' => '/', 'ip_hash' => hash('sha256', 'one address'), 'created_at' => now()]));

    $page = Livewire::withQueryParams(['view' => 'sites', 'list' => 'site'])->test(TrafficSourcesPage::class)->call('load');
    $rows = collect($page->viewData('list')['rows'])->keyBy('ref');

    expect($rows['70001']['figures']->flaggedRate())->toBe(1.0)
        ->and($rows['70001']['figures']->repeatAddressRate())->toBeNull()
        ->and($rows['70002']['figures']->flaggedRate())->toBe(0.0)
        ->and($rows['70002']['figures']->repeatAddressRate())->toBe(1.0);

    $page->assertSee('Flagged')
        ->assertSee('Repeat address')
        ->assertSee('No browser headers 4')
        ->assertSee('of 4')
        ->call('sortBy', 'flagged')->assertSeeInOrder(['site 70001', 'site 70002'])
        ->call('sortBy', 'repeat')->assertSeeInOrder(['site 70002', 'site 70001']);
});

it('keeps only the sites whose share in a column is above or below a percent, past the clicks threshold', function () {
    siteClicks($this->source, '72001', 40, 10);
    siteClicks($this->source, '72002', 40, 30);
    siteClicks($this->source, '72003', 5, 1);

    $page = Livewire::withQueryParams(['view' => 'sites', 'list' => 'site'])->test(TrafficSourcesPage::class)->call('load')
        ->set('minClicks', 10)
        ->set('shareColumn', 'opened')
        ->set('sharePercent', '50');

    expect(collect($page->viewData('list')['rows'])->pluck('ref')->all())->toBe(['72001']);

    $page->set('shareSide', 'above')->assertSee('site 72002')->assertDontSee('site 72001')
        // 75% exactly is on neither side of 75.
        ->set('sharePercent', '75')->assertDontSee('site 72002')
        ->set('sharePercent', 'many')->assertSet('sharePercent', '')->assertSee('site 72001')->assertSee('site 72002')
        ->set('shareColumn', 'nonsense')->assertSet('shareColumn', '');
});

it('compares a share as the column shows it, says when the filters left every row out, and clears the share to find one row', function () {
    // 1 of 50 opened started at each: 2.00% on screen, on neither side of 2.
    siteClicks($this->source, '73001', 50, 50);
    siteClicks($this->source, '73002', 50, 50);
    TrafficVisit::query()->whereIn('site_ref', ['73001', '73002'])->get()->groupBy('site_ref')->each(fn ($visits) => $visits->first()->forceFill(['started_at' => now()])->save());

    $page = Livewire::withQueryParams(['view' => 'sites', 'list' => 'site', 'share' => 'started', 'than' => 'below', 'pct' => '2'])->test(TrafficSourcesPage::class)->call('load')
        ->assertSee('None clear the clicks threshold, the search and the share above it: 2 are left out.')
        ->assertDontSee('No ad network sent a click');

    $page->set('shareSide', 'above')->assertDontSee('site 73001')->assertDontSee('site 73002')
        ->call('listSites', $this->source->id, 'site', '73001')
        ->assertSet('shareColumn', '')
        ->assertSet('sharePercent', '')
        ->assertSee('site 73001')
        ->assertSee('2.00%');
});

it('calls a site that has cost past the money’s gate a weak start while the worth isn’t measured, saying how often its visitors started the game against the source', function () {
    // 300 clicks at a cent: past the rule's 200 clicks, and no run of chances measures the worth. This source judges a start from 250 clicks, not 1,000.
    $this->source->forceFill(['block_start_clicks' => 250])->save();
    siteClicks($this->source, '71001', 300, 200);
    TrafficVisit::query()->where('site_ref', '71001')->limit(2)->update(['started_at' => now()]);
    siteClicks($this->source, '71002', 300, 200);
    TrafficVisit::query()->where('site_ref', '71002')->limit(40)->update(['started_at' => now()]);

    $page = Livewire::withQueryParams(['view' => 'sites', 'list' => 'site'])->test(TrafficSourcesPage::class)->call('load');
    $rows = collect($page->viewData('list')['rows'])->keyBy('ref');

    expect($rows['71001']['state'])->toBe('weak_start')
        ->and($rows['71002']['state'])->toBe('early')
        ->and($rows['71001']['flag']['tip'])->toContain('1% of the 200 who opened the page started it, under 60% of the source’s 10.5%');
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

    // Active: every site not blocked — here the one just unblocked — kept in the address.
    $page->set('segment', 'active')
        ->assertSet('segment', 'active')
        ->assertSee('site 70001')
        ->assertDontSee('site 70002');

    expect($page->viewData('list')['counts'])->toMatchArray(['all' => 27, 'active' => 1, 'blocked' => 26]);
});

it('says on a blocked site where the network’s list stands: on its way there or off it, refused, or a taking off that failed', function () {
    foreach (['70001' => TrafficBlockNetworkState::Pending, '70002' => TrafficBlockNetworkState::Removing, '70003' => TrafficBlockNetworkState::Failed, '70004' => TrafficBlockNetworkState::Applied, '70005' => TrafficBlockNetworkState::Applied] as $site => $state) {
        // Numeric keys come back as numbers.
        $site = (string) $site;
        siteClicks($this->source, $site, 1, 1);
        TrafficBlock::factory()->create(['source_id' => $this->source->id, 'level' => TrafficBlockLevel::Site, 'ref' => $site, 'method' => 'api', 'network_state' => $state,
            'network_error' => match ($site) {
                '70003' => 'shared_list',
                '70005' => 'rejected',
                default => null,
            }]);
    }

    Livewire::withQueryParams(['view' => 'sites', 'list' => 'site', 'segment' => 'blocked'])->test(TrafficSourcesPage::class)->call('load')
        ->assertSee('Blocking…')
        ->assertSee('Unblocking…')
        ->assertSee('Not blocked on the network')
        ->assertSee('Blocked · unblock failed')
        ->assertSee('Still on the network’s list: The network refused the change. Unblock it again to try again.', false);
});

it('says on a site some campaigns took and others refused how many lists it is on, naming those that refused and why', function () {
    $this->source->forceFill(['connector' => TrafficConnector::PopAds])->save();
    $us = TrafficCampaign::factory()->create(['source_id' => $this->source->id, 'name' => 'US popunder']);
    $eu = TrafficCampaign::factory()->create(['source_id' => $this->source->id, 'name' => 'EU popunder']);
    $asia = TrafficCampaign::factory()->create(['source_id' => $this->source->id, 'name' => 'Asia popunder']);
    $block = function (string $site, TrafficBlockNetworkState $state, ?string $error, array $listings): void {
        siteClicks($this->source, $site, 1, 1);
        $block = TrafficBlock::factory()->create(['source_id' => $this->source->id, 'level' => TrafficBlockLevel::Site, 'ref' => $site, 'method' => 'api', 'network_state' => $state, 'network_error' => $error, 'blocked_at' => now()->subDay()]);

        foreach ($listings as [$campaign, $listed, $why]) {
            TrafficBlockListing::factory()->create(['block_id' => $block->id, 'campaign_id' => $campaign->id, 'listed' => $listed, 'error' => $why]);
        }
    };
    $block('70001', TrafficBlockNetworkState::Partial, 'rejected', [[$us, true, null], [$eu, false, 'rejected']]);
    $block('70002', TrafficBlockNetworkState::TakenOff, null, [[$us, false, 'taken_off'], [$eu, false, 'taken_off']]);
    $block('70003', TrafficBlockNetworkState::Failed, 'rejected', [[$us, false, 'rejected'], [$eu, false, 'unavailable']]);
    // An unblock that took it off one campaign, refused on the other.
    $block('70004', TrafficBlockNetworkState::Partial, 'shared_list', [[$us, false, null], [$eu, true, 'shared_list']]);
    // The same, on a block a third campaign had refused to take: it was never on that list, so it was never taken off it.
    $block('70005', TrafficBlockNetworkState::Partial, 'rejected', [[$us, false, null], [$eu, true, 'rejected'], [$asia, false, 'rejected']]);

    $page = Livewire::withQueryParams(['view' => 'sites', 'list' => 'site', 'seg' => 'blocked'])->test(TrafficSourcesPage::class)->call('load');
    $flags = collect($page->viewData('list')['rows'])->mapWithKeys(fn (array $row): array => [$row['ref'] => $row['flag']]);

    expect($flags['70001'])->toMatchArray(['label' => 'Partly blocked · 1 of 2 campaigns', 'tone' => 'warning'])
        ->and($flags['70001']['tip'])->toBe('Blocked 16 Nov through the network · on the list of US popunder · not on EU popunder’s: The network refused the change.')
        ->and($flags['70002'])->toMatchArray(['label' => 'Taken off on the network', 'tone' => 'warning'])
        ->and($flags['70002']['tip'])->toContain('taken off the lists of US popunder, EU popunder in the PopAds panel, not by RateGuru')
        ->and($flags['70002']['tip'])->toContain('Push again to put it back, or Unblock to let it go.')
        ->and($flags['70003'])->toMatchArray(['label' => 'Not blocked on the network', 'tone' => 'danger'])
        ->and($flags['70003']['tip'])->toContain('not on US popunder’s: The network refused the change. · not on EU popunder’s: The network’s API is down.')
        ->and($flags['70004'])->toMatchArray(['label' => 'Partly blocked · unblock failed', 'tone' => 'danger'])
        ->and($flags['70004']['tip'])->toContain('Still on the list of EU popunder: The campaign blocks the sites of a list shared with other campaigns')
        ->and($flags['70004']['tip'])->toContain('Off the list of US popunder. Unblock it again to try again.')
        ->and($flags['70005']['tip'])->toBe('Still on the list of EU popunder: The network refused the change. Off the list of US popunder. Unblock it again to try again.');
    $page->assertSee('Partly blocked · 1 of 2 campaigns');
});

it('says on a site blocked in the network’s own panel that it is blocked there, counts it under Blocked and never offers to block it again', function () {
    $this->source->forceFill(['connector' => TrafficConnector::PopAds])->save();
    $campaign = TrafficCampaign::factory()->create(['source_id' => $this->source->id, 'name' => 'US popunder']);
    // Bots by the rule's reckoning: 40 clicks, 1 opening the page.
    siteClicks($this->source, '50001', 40, 1, ['campaign_id' => $campaign->id]);
    $block = TrafficBlock::factory()->create(['source_id' => $this->source->id, 'level' => TrafficBlockLevel::Site, 'ref' => '50001', 'method' => TrafficBlockMethod::Panel, 'network_state' => TrafficBlockNetworkState::Applied, 'blocked_at' => now()->subDay()]);
    TrafficBlockListing::factory()->create(['block_id' => $block->id, 'campaign_id' => $campaign->id, 'listed' => true]);

    $page = Livewire::withQueryParams(['view' => 'sites', 'list' => 'site'])->test(TrafficSourcesPage::class)->call('load');

    expect($page->viewData('worthBlocking'))->toBe(0)
        ->and($page->viewData('list')['counts'])->toMatchArray(['blocked' => 1, 'block' => 0]);

    $page->set('segment', 'blocked')
        ->assertSee('Blocked on PopAds')
        ->assertSee('Blocked in the PopAds panel, not by RateGuru · found 16 Nov · on 1 campaign’s list', false);
});

it('says on a site blocked in the network’s panel whose unblock a campaign refused that the unblock failed, and where it stays', function () {
    $this->source->forceFill(['connector' => TrafficConnector::PopAds])->save();
    $us = TrafficCampaign::factory()->create(['source_id' => $this->source->id, 'name' => 'US popunder']);
    $eu = TrafficCampaign::factory()->create(['source_id' => $this->source->id, 'name' => 'EU popunder']);
    siteClicks($this->source, '50001', 1, 1);
    $block = TrafficBlock::factory()->create(['source_id' => $this->source->id, 'level' => TrafficBlockLevel::Site, 'ref' => '50001', 'method' => TrafficBlockMethod::Panel, 'network_state' => TrafficBlockNetworkState::Applied, 'network_error' => 'rejected', 'blocked_at' => now()->subDay()]);
    TrafficBlockListing::factory()->create(['block_id' => $block->id, 'campaign_id' => $us->id, 'listed' => false]);
    TrafficBlockListing::factory()->create(['block_id' => $block->id, 'campaign_id' => $eu->id, 'listed' => true, 'error' => 'rejected']);

    $page = Livewire::withQueryParams(['view' => 'sites', 'list' => 'site', 'seg' => 'blocked'])->test(TrafficSourcesPage::class)->call('load');
    $flag = collect($page->viewData('list')['rows'])->firstWhere('ref', '50001')['flag'];

    expect($flag)->toMatchArray(['label' => 'Blocked on PopAds · unblock failed', 'tone' => 'danger'])
        ->and($flag['tip'])->toBe('Blocked in the PopAds panel, not by RateGuru · still on the list of EU popunder: The network refused the change. Unblock it again to try again.');
});

it('pushes the ticked blocks again to the network from Blocked', function () {
    $this->source->forceFill(['connector' => TrafficConnector::PopAds, 'block_level' => TrafficBlockLevel::Site])->save();
    TrafficApiConnection::factory()->create(['source_id' => $this->source->id]);
    siteClicks($this->source, '70001', 1, 1);
    $block = TrafficBlock::factory()->create(['source_id' => $this->source->id, 'level' => TrafficBlockLevel::Site, 'ref' => '70001', 'method' => 'api', 'network_state' => TrafficBlockNetworkState::Partial, 'network_error' => 'rejected']);

    Livewire::withQueryParams(['view' => 'sites', 'list' => 'site', 'seg' => 'blocked'])->test(TrafficSourcesPage::class)->call('load')
        ->call('toggleSelected', $this->source->id.':site:70001')
        ->assertSee('Push again')
        ->call('pushSelected')
        ->assertSet('selected', []);

    expect($block->fresh())->network_state->toBe(TrafficBlockNetworkState::Pending)->network_error->toBeNull();
});

it('pushes again and blocks every site Select all ticks, more than one batch holds, a batch at a time', function () {
    $this->source->forceFill(['connector' => TrafficConnector::PopAds, 'block_level' => TrafficBlockLevel::Site])->save();
    TrafficApiConnection::factory()->create(['source_id' => $this->source->id]);
    $many = BlockTrafficRefsAction::MOST_REFS + 1;
    TrafficBlock::factory()->count($many)->sequence(fn ($sequence): array => ['ref' => (string) (80001 + $sequence->index)])
        ->create(['source_id' => $this->source->id, 'level' => TrafficBlockLevel::Site, 'method' => 'api', 'network_state' => TrafficBlockNetworkState::Failed, 'network_error' => 'rejected']);
    TrafficVisit::factory()->count($many)->sequence(fn ($sequence): array => ['site_ref' => (string) (90001 + $sequence->index)])
        ->create(['source_id' => $this->source->id, 'arrived_at' => '2026-11-16 10:00:00', 'network_day' => '2026-11-16', 'opened_at' => now(), 'spend' => '0.01']);

    $page = Livewire::withQueryParams(['view' => 'sites', 'list' => 'site', 'seg' => 'blocked'])->test(TrafficSourcesPage::class)->call('load')
        ->call('selectMatching')
        ->assertCount('selected', $many)
        ->call('pushSelected')
        ->assertSet('selected', [])
        ->assertDispatched('rg-admin-toast', message: $many.' queued for the network again.');

    $batches = fn (): array => TrafficBlockEvent::query()->where('kind', 'queued')->orderBy('id')->get()->groupBy('note')
        ->map(fn ($lines): array => $lines->pluck('ref_count')->sort()->values()->all())->all();

    // Each batch a line of its own in the log, as one push of so many would write.
    expect(TrafficBlock::query()->where('network_state', TrafficBlockNetworkState::Pending)->count())->toBe($many)
        ->and($batches())->toBe(['Waiting to go to the network’s list again' => [1, BlockTrafficRefsAction::MOST_REFS]]);

    $page->set('segment', 'all')
        ->call('selectMatching')
        ->assertCount('selected', $many)
        ->call('blockSelected')
        ->assertSet('selected', [])
        ->assertDispatched('rg-admin-toast', message: $many.' queued for the network.');

    expect(TrafficBlock::query()->where('ref', 'like', '9%')->where('network_state', TrafficBlockNetworkState::Pending)->count())->toBe($many)
        ->and($batches())->toBe([
            'Waiting to go to the network’s list again' => [1, BlockTrafficRefsAction::MOST_REFS],
            'Waiting to be added to the network’s list' => [1, BlockTrafficRefsAction::MOST_REFS],
        ]);
});

it('keeps ticked only the sites a batch that failed, or never went, was for, saying how many went through', function () {
    $this->source->forceFill(['connector' => TrafficConnector::PopAds, 'block_level' => TrafficBlockLevel::Site])->save();
    TrafficApiConnection::factory()->create(['source_id' => $this->source->id]);
    $many = BlockTrafficRefsAction::MOST_REFS + 1;
    TrafficVisit::factory()->count($many)->sequence(fn ($sequence): array => ['site_ref' => (string) (90001 + $sequence->index)])
        ->create(['source_id' => $this->source->id, 'arrived_at' => '2026-11-16 10:00:00', 'network_day' => '2026-11-16', 'opened_at' => now(), 'spend' => '0.01']);
    // The administrator stops being one once the first batch is queued: the second is refused.
    TrafficBlockEvent::created(function (): void {
        User::query()->whereKey(auth()->id())->update(['role' => UserRole::Moderator]);
    });

    $page = Livewire::withQueryParams(['view' => 'sites', 'list' => 'site'])->test(TrafficSourcesPage::class)->call('load')
        ->call('selectMatching')
        ->call('blockSelected')
        ->assertDispatched('rg-admin-toast', message: BlockTrafficRefsAction::MOST_REFS.' went through; the rest are still ticked. You can’t manage traffic sources any more, so nothing was saved.')
        ->assertCount('selected', 1);

    $left = (string) str($page->get('selected')[0])->afterLast(':');

    expect(TrafficBlock::query()->count())->toBe(BlockTrafficRefsAction::MOST_REFS)
        ->and(TrafficBlock::query()->where('ref', $left)->exists())->toBeFalse();
});

it('counts in its toast only the sites a Block changed, never those blocked already', function () {
    $this->source->forceFill(['connector' => TrafficConnector::PopAds, 'block_level' => TrafficBlockLevel::Site])->save();
    TrafficApiConnection::factory()->create(['source_id' => $this->source->id]);
    siteClicks($this->source, '70001', 1, 1);
    siteClicks($this->source, '70002', 1, 1);
    TrafficBlock::factory()->create(['source_id' => $this->source->id, 'level' => TrafficBlockLevel::Site, 'ref' => '70001', 'method' => 'api', 'network_state' => TrafficBlockNetworkState::Applied]);

    Livewire::withQueryParams(['view' => 'sites', 'list' => 'site'])->test(TrafficSourcesPage::class)->call('load')
        ->set('selected', [$this->source->id.':site:70001', $this->source->id.':site:70002'])
        ->call('blockSelected')
        ->assertDispatched('rg-admin-toast', message: '1 queued for the network.');
});

it('keeps ticked, for Copy IDs, the blocks Push again can’t send because their source’s API is not connected', function () {
    $this->source->forceFill(['connector' => TrafficConnector::PopAds, 'block_level' => TrafficBlockLevel::Site])->save();
    TrafficApiConnection::factory()->create(['source_id' => $this->source->id]);
    $offline = TrafficSource::factory()->create(['name' => 'PopAds second', 'connector' => TrafficConnector::PopAds, 'block_level' => TrafficBlockLevel::Site]);

    foreach ([[$this->source, '70001'], [$offline, '70002']] as [$source, $site]) {
        siteClicks($source, $site, 1, 1);
        TrafficBlock::factory()->create(['source_id' => $source->id, 'level' => TrafficBlockLevel::Site, 'ref' => $site, 'method' => 'api', 'network_state' => TrafficBlockNetworkState::Failed, 'network_error' => 'rejected']);
    }

    Livewire::withQueryParams(['view' => 'sites', 'list' => 'site', 'seg' => 'blocked'])->test(TrafficSourcesPage::class)->call('load')
        ->set('selected', [$this->source->id.':site:70001', $offline->id.':site:70002'])
        ->call('pushSelected')
        ->assertDispatched('rg-admin-toast', message: '1 queued for the network again. 1 can’t go through their network’s API: add them to its list by hand — they are still ticked for Copy IDs.')
        ->assertSet('selected', [$offline->id.':site:70002']);
});

it('keeps every site Select all ticks, past two thousand, through a change of search, threshold or segment', function () {
    $this->source->forceFill(['connector' => TrafficConnector::PopAds, 'block_level' => TrafficBlockLevel::Site])->save();
    TrafficApiConnection::factory()->create(['source_id' => $this->source->id]);
    $many = 2001;
    $visits = TrafficVisit::factory()->count($many)->sequence(fn ($sequence): array => ['site_ref' => (string) (90001 + $sequence->index)])
        ->make(['source_id' => $this->source->id, 'arrived_at' => '2026-11-16 10:00:00', 'network_day' => '2026-11-16', 'opened_at' => now(), 'spend' => '0.01']);

    foreach ($visits->chunk(250) as $chunk) {
        TrafficVisit::query()->insert($chunk->map(fn (TrafficVisit $visit): array => $visit->getAttributes())->values()->all());
    }

    // Searched down to one site: the other 2,000 ticked are out of view, and stay ticked.
    $page = Livewire::withQueryParams(['view' => 'sites', 'list' => 'site'])->test(TrafficSourcesPage::class)->call('load')
        ->call('selectMatching')
        ->assertCount('selected', $many)
        ->set('minClicks', 1)
        ->set('segment', 'all')
        ->set('q', '90001')
        ->assertCount('selected', $many);

    expect(collect($page->viewData('list')['rows'])->pluck('ref')->all())->toBe(['90001'])
        ->and($page->get('selected'))->toContain($this->source->id.':site:92001');

    $page->call('blockSelected')
        ->assertSet('selected', [])
        ->assertDispatched('rg-admin-toast', message: $many.' queued for the network.');

    expect(TrafficBlock::query()->where('network_state', TrafficBlockNetworkState::Pending)->count())->toBe($many);
});

it('ticks, keeps and blocks a site whose ID is 120 characters of a script written in more than one byte', function () {
    $this->source->forceFill(['connector' => TrafficConnector::PopAds, 'block_level' => TrafficBlockLevel::Site])->save();
    TrafficApiConnection::factory()->create(['source_id' => $this->source->id]);
    $long = str_repeat('я', 120);
    foreach (['40123', $long] as $site) {
        TrafficVisit::factory()->create(['source_id' => $this->source->id, 'site_ref' => $site, 'arrived_at' => '2026-11-16 10:00:00', 'network_day' => '2026-11-16', 'opened_at' => now(), 'spend' => '0.01']);
    }
    $key = $this->source->id.':site:'.$long;

    Livewire::withQueryParams(['view' => 'sites', 'list' => 'site'])->test(TrafficSourcesPage::class)->call('load')
        ->call('toggleSelected', $key)
        ->assertSet('selected', [$key])
        ->call('clearSelection')
        ->call('selectMatching')
        ->set('segment', 'all')
        ->assertCount('selected', 2)
        ->call('blockSelected')
        ->assertDispatched('rg-admin-toast', message: '2 queued for the network.');

    expect(TrafficBlock::query()->pluck('ref')->sort()->values()->all())->toBe(['40123', $long]);
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

it('names the spend still estimated under the Spend tile, says so when all of it is counted, and when nothing was spent', function () {
    $tile = fn (array $query = []) => Livewire::withQueryParams($query)->test(TrafficSourcesPage::class)->call('load');

    TrafficVisit::factory()->unpaid(TrafficChannel::Direct)->create(['arrived_at' => '2026-11-16 10:00:00', 'network_day' => '2026-11-16', 'opened_at' => now()]);

    $tile()->assertSee('Nothing spent on these filters')->assertDontSee('estimated, not synced yet');

    siteClicks($this->source, '40123', 2, 2);

    $tile()->assertSeeInOrder(['Spend', '$0.02', 'All counted, none estimated'])->assertDontSee('estimated, not synced yet');

    siteClicks($this->source, '40124', 3, 3, ['spend_estimated' => true]);

    $tile()->assertSeeInOrder(['Spend', '$0.05', 'incl. $0.03 estimated, not synced yet'])->assertDontSee('All counted, none estimated');
    $tile(['type' => 'unpaid'])->assertSee('Nothing spent on these filters');
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
        ->assertSee('The network’s impressions aren’t split by device, city or game, so they’re left out on these filters.')
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
        ->assertSee('67.14%')
        ->assertDontSeeHtml('data-testid="traffic-sites-reach-note"')
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

    // On a device the network has no impressions to set beside the visits: dashes, no hint, and a note saying why.
    $onMobile = Livewire::withQueryParams(['view' => 'sites', 'list' => 'site', 'device' => 'mobile'])->test(TrafficSourcesPage::class)->call('load');
    $mobile = collect($onMobile->viewData('list')['rows'])->keyBy('ref');

    expect($mobile['70001']['reach'])->toBeNull()
        ->and($mobile['70001']['state'])->toBe('early');

    $onMobile->assertSeeHtml('data-testid="traffic-sites-reach-note"')
        ->assertSeeText('Paid impressions are counted by day, campaign, site and country, not by device, city or game. On these filters Paid impr. and Reached show “—” and no site is marked Low reach.');
});

it('says nothing of impressions left out of Sites & zones where the period has none', function () {
    $campaign = TrafficCampaign::factory()->create(['source_id' => $this->source->id]);
    reachedVisits($this->source, '2026-11-16', 3, 3, 0, ['campaign_id' => $campaign->id, 'site_ref' => '70005']);

    Livewire::withQueryParams(['view' => 'sites', 'list' => 'site', 'device' => 'mobile'])
        ->test(TrafficSourcesPage::class)
        ->call('load')
        ->assertSee('70005')
        ->assertDontSeeHtml('data-testid="traffic-sites-reach-note"');
});

it('lists a place the network billed that sent no visit at all once its closed days reach the impressions asked, judged, counted and shown past the clicks threshold', function () {
    config(['traffic.reach.min_impressions' => 50, 'traffic.reach.below_percent_of_source' => 70]);
    $campaign = TrafficCampaign::factory()->create(['source_id' => $this->source->id, 'name' => 'US popunder']);
    $other = TrafficCampaign::factory()->create(['source_id' => $this->source->id, 'name' => 'DE popunder']);
    $billed = fn (string $day, int $impressions, array $where, ?TrafficCampaign $through = null) => paidImpressions($this->source, $day, $impressions, '0.10', ['campaign_id' => ($through ?? $campaign)->id, ...$where]);
    // Yesterday, closed. Sites the visits came from: 70001 by itself, 70005 through its zone 555 — not a place without visits.
    $billed('2026-11-16', 100, ['site_ref' => '70001']);
    reachedVisits($this->source, '2026-11-16', 80, 80, 0, ['campaign_id' => $campaign->id, 'site_ref' => '70001']);
    $billed('2026-11-16', 80, ['site_ref' => '70005']);
    reachedVisits($this->source, '2026-11-16', 60, 60, 0, ['campaign_id' => $campaign->id, 'site_ref' => '70005', 'zone_ref' => '555']);
    // No visit at all: site 70002, and zone 9001 of site 70004, enough impressions; site 70003 too few; site 70007 through two campaigns.
    $billed('2026-11-16', 200, ['site_ref' => '70002']);
    $billed('2026-11-16', 60, ['site_ref' => '70004', 'zone_ref' => '9001']);
    $billed('2026-11-16', 30, ['site_ref' => '70003']);
    $billed('2026-11-16', 60, ['site_ref' => '70007']);
    $billed('2026-11-16', 60, ['site_ref' => '70007'], $other);
    // Today, not closed: however many, its impressions are not judged yet.
    $billed('2026-11-17', 500, ['site_ref' => '70006']);
    // The source reached 140 of 590 closed impressions, 24%: 70% of it is 17%.
    $open = fn (array $query = []) => Livewire::withQueryParams(['view' => 'sites', ...$query])->test(TrafficSourcesPage::class)->call('load');

    $sites = $open(['list' => 'site']);
    $rows = collect($sites->viewData('list')['rows'])->keyBy('ref');

    expect($rows->pluck('ref')->sort()->values()->all())->toBe(['70001', '70002', '70005', '70007'])
        ->and($rows['70002'])->toMatchArray(['key' => $this->source->id.':site:70002', 'level' => 'site', 'site' => null, 'source' => 'PopAds main', 'campaign' => 'US popunder', 'state' => 'low_reach', 'blocked' => false])
        ->and($rows['70002']['figures']->visits)->toBe(0)
        ->and([$rows['70002']['reach']->impressions, $rows['70002']['reach']->reached, $rows['70002']['reach']->reachRate()])->toBe([200, 0, 0.0])
        ->and($rows['70002']['flag'])->toBe(['label' => 'Low reach', 'tone' => 'warning', 'tip' => 'Paid impressions mostly never reach RateGuru: 0% reached against 24% for the source, over 200 paid impressions of closed days'])
        ->and($rows['70007'])->toMatchArray(['campaign' => 'In 2 campaigns', 'state' => 'low_reach'])
        ->and([$rows['70007']['reach']->impressions, $rows['70005']['reach']->reached, $rows['70001']['state']])->toBe([120, 60, 'early'])
        ->and($sites->viewData('list')['counts'])->toMatchArray(['all' => 4, 'block' => 2])
        ->and($sites->viewData('worthBlocking'))->toBe(2);

    $sites->assertSee('site 70002')->assertDontSee('site 70003')->assertDontSee('site 70006');

    // By zone, the zone without a visit is a row too, under its site; site 70005's clicks show as its zone, so it is no row of its own.
    $zones = $open();
    $rows = collect($zones->viewData('list')['rows'])->keyBy('key');

    expect($rows->keys()->sort()->values()->all())->toBe(collect(['site:70001', 'site:70002', 'site:70007', 'zone:555', 'zone:9001'])->map(fn (string $key): string => $this->source->id.':'.$key)->sort()->values()->all())
        ->and($rows[$this->source->id.':zone:9001'])->toMatchArray(['level' => 'zone', 'ref' => '9001', 'site' => '70004', 'campaign' => 'US popunder', 'state' => 'low_reach'])
        ->and($rows[$this->source->id.':zone:555']['reach'])->toBeNull()
        ->and($zones->viewData('list')['counts']['block'])->toBe(3)
        // Worth blocking on the report counts them as the list by zone does; the campaign's count, the places of it alone.
        ->and(Livewire::test(TrafficSourcesPage::class)->call('load')->viewData('worthBlocking'))->toBe(3);

    $toBlock = collect(Livewire::withQueryParams(['tab' => 'campaigns'])->test(TrafficSourcesPage::class)->call('load')->viewData('campaignGroups'))
        ->flatMap(fn (array $group): array => collect($group['rows'])->all())->mapWithKeys(fn (array $row): array => [$row['id'] => $row['toBlock']]);

    expect($toBlock->all())->toEqual([$campaign->id => 2, $other->id => 0]);

    // Fewer clicks than the threshold hide the rest; Low reach is judged by its impressions and stays, with none.
    $sites->set('minClicks', 100);

    expect(collect($sites->viewData('list')['rows'])->pluck('ref')->sort()->values()->all())->toBe(['70002', '70007'])
        ->and($sites->viewData('list')['hidden'])->toBe(2);

    // Sorted by the share reached, the places that reached nothing lead upwards and trail downwards, before any row with no impressions.
    $refs = fn (): array => collect($sites->viewData('list')['rows'])->pluck('ref')->all();
    $sites->set('minClicks', 0)->call('sortBy', 'reach');

    expect($refs())->toBe(['70001', '70005', '70002', '70007']);

    $sites->call('sortBy', 'reach');

    expect($refs())->toBe(['70002', '70007', '70005', '70001']);

    // Narrowed to the other campaign, only what it was billed counts: site 70007 is its own there.
    $theirs = collect($open(['list' => 'site', 'campaign' => (string) $other->id])->viewData('list')['rows'])->keyBy('ref');

    expect($theirs->pluck('ref')->all())->toBe(['70007'])
        ->and($theirs['70007'])->toMatchArray(['campaign' => 'DE popunder', 'state' => 'low_reach'])
        // On a device the network has no impressions to set beside the visits: none of them.
        ->and(collect($open(['list' => 'site', 'device' => 'mobile'])->viewData('list')['rows'])->pluck('ref')->sort()->values()->all())->toBe(['70001', '70005']);
});

it('pages, ticks across pages and blocks a place the network billed that sent no visit like any other site', function () {
    config(['traffic.reach.min_impressions' => 50, 'traffic.reach.below_percent_of_source' => 70]);
    paidImpressions($this->source, '2026-11-16', 100, '0.20', ['site_ref' => '70001']);
    reachedVisits($this->source, '2026-11-16', 90, 90, 0, ['site_ref' => '70001', 'spend' => '0.01']);
    paidImpressions($this->source, '2026-11-16', 300, '0.60', ['site_ref' => '70002']);

    // Twenty-five sites more with a cent spent each: by spend, the place that spent nothing is on the second page.
    foreach (range(1, 25) as $site) {
        siteClicks($this->source, (string) (60000 + $site), 1, 1);
    }

    $key = $this->source->id.':site:70002';
    $page = Livewire::withQueryParams(['view' => 'sites', 'list' => 'site'])->test(TrafficSourcesPage::class)->call('load')
        ->assertDontSee('site 70002')
        ->call('goToPage', 2)
        ->assertSee('site 70002');

    expect($page->viewData('list')['matching'])->toContain($key)
        ->and($page->viewData('list')['counts'])->toMatchArray(['all' => 27, 'block' => 1]);

    $page->set('segment', 'block')
        ->call('selectMatching')
        ->assertSet('selected', [$key])
        ->set('segment', 'all')
        ->call('goToPage', 1)
        ->call('selectMatching');

    expect($page->get('selected'))->toContain($key)->toHaveCount(27);

    $page->set('selected', [$key])->call('blockSelected');

    expect(TrafficBlock::query()->where('source_id', $this->source->id)->where('level', TrafficBlockLevel::Site)->where('ref', '70002')->exists())->toBeTrue();

    // Blocked, it is listed once, in Blocked: no longer worth blocking.
    expect(array_count_values($page->viewData('list')['matching'])[$key])->toBe(1)
        ->and($page->viewData('list')['counts'])->toMatchArray(['all' => 27, 'block' => 0, 'blocked' => 1]);

    $page->set('segment', 'blocked');

    expect(collect($page->viewData('list')['rows'])->pluck('key')->all())->toBe([$key]);
});

it('lists a place that sent no visit only where Low reach judges it, and never as Profitable on the nothing it spent', function () {
    config(['traffic.reach.min_impressions' => 50, 'traffic.reach.below_percent_of_source' => 70]);
    // The link carries no site macro: the visits came, yet none of them the site the network billed, so the source reached nothing at all.
    paidImpressions($this->source, '2026-11-16', 200, '0.40', ['site_ref' => '70002', 'counted' => true]);
    reachedVisits($this->source, '2026-11-16', 80, 80);
    $open = fn () => Livewire::withQueryParams(['view' => 'sites', 'list' => 'site'])->test(TrafficSourcesPage::class)->call('load');

    $page = $open();

    expect($page->viewData('list')['rows'])->toBeEmpty()
        ->and($page->viewData('list')['counts']['all'])->toBe(0)
        ->and($page->viewData('worthBlocking'))->toBe(0);

    // Once a site of the source reaches RateGuru, the one that reached nothing is Low reach — even where the money's gate is 0 and the worth
    // is measured, which call a place with no figures profitable, and its figure is one the spend was shared out from.
    $run = TrafficChanceRun::query()->forceCreate(['status' => 'finished', 'reason' => 'nightly', 'price_set_id' => TrafficPriceSet::current()->id, 'matured_before' => now(), 'started_at' => now(), 'finished_at' => now()]);
    TrafficStepChance::query()->forceCreate(['run_id' => $run->id, 'slice_key' => '*', 'step' => 'opened', 'visitors' => 900, 'accounts' => 30, 'slice_accounts' => 30, 'chance' => '0.03333333', 'thin' => false, 'stands_on' => '*']);
    $this->source->forceFill(['block_min_spend' => '0'])->save();
    paidImpressions($this->source, '2026-11-16', 100, '0.20', ['site_ref' => '70001']);
    reachedVisits($this->source, '2026-11-16', 100, 100, 0, ['site_ref' => '70001']);

    $page = $open();
    $rows = collect($page->viewData('list')['rows'])->keyBy('ref');

    expect($rows->map(fn (array $row): string => $row['state'])->sortKeys()->all())->toBe(['70001' => 'profit', '70002' => 'low_reach'])
        ->and($rows['70002']['flag']['label'])->toBe('Low reach')
        ->and($page->viewData('list')['counts'])->toMatchArray(['all' => 2, 'block' => 1, 'profit' => 1])
        ->and($page->viewData('worthBlocking'))->toBe(1);
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
        ->assertSeeInOrder(['Day', 'Impressions', 'Clicks', 'Reached', 'From click costs', 'From the network', 'In reports'])
        ->assertSeeInOrder(['Yesterday', '40', '30', '30', '75.0%']);
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
        // Never read from an API: when it was first seen stands where its targeting will.
        ->set('stage', 'new')->assertSeeInOrder(['New campaign 881377', 'Not set yet', 'First seen 17 Nov 12:00'])
        ->call('openCampaign', $new->id)->assertSet('camp', (string) $new->id)
        // A network's campaign, its type and its sites ticked: none of it the banner's, so its report drops them.
        ->set('campaignId', (string) $scaling->id)->set('type', 'network')->set('selected', [$network->id.':site:47494'])
        ->call('reportFor', $banner->id)->assertSet('tab', 'report')->assertSet('source', (string) $banner->id)
        ->assertSet('campaignId', '')->assertSet('type', '')->assertSet('selected', []);
});

it('shows a new campaign the network API read by its name and targeting, leaving only its stage to choose', function () {
    TrafficCampaign::factory()->create(['source_id' => $this->source->id, 'external_id' => '9612052', 'status' => 'new', 'name' => 'PopAds - Tabup/Mobile/All countries - #9612052', 'countries' => null, 'devices' => ['mobile'], 'format' => 'Popunder', 'first_click_at' => '2026-11-16 08:00:00', 'synced_at' => '2026-11-16 09:00:00']);

    $page = Livewire::withQueryParams(['tab' => 'campaigns'])->test(TrafficSourcesPage::class)->call('load')
        ->assertSee('PopAds main campaign “PopAds - Tabup/Mobile/All countries - #9612052” is new')
        ->assertSee('its name, targeting, bid and budget come from the PopAds main API')
        ->assertSee('Choose its stage')
        ->assertDontSee('Set it up')
        ->assertDontSee('New campaign 9612052')
        ->assertDontSee('Not set yet')
        // The targeting the API read, the day it was first seen kept in its stage rather than in place of it.
        ->assertSeeHtmlInOrder(['PopAds - Tabup/Mobile/All countries - #9612052', 'Every country', 'Phones only · Popunder', 'New', 'since 16 Nov'])
        ->assertDontSee('First seen');
    $row = collect($page->viewData('campaignGroups'))->flatMap(fn (array $group): array => $group['rows'])->firstWhere('name', 'PopAds - Tabup/Mobile/All countries - #9612052');

    expect($row)->toMatchArray(['new' => true, 'synced' => true, 'countries' => null, 'stage' => 'new']);
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

it('names in Logs → Clicks each sign of a bot a click carried', function () {
    $visit = TrafficVisit::factory()->create(['source_id' => $this->source->id, 'arrived_at' => now()->subHour(), 'opened_at' => null, 'bot_flags' => BotSignals::HEADLESS | BotSignals::BURST]);
    TrafficVisitDetail::query()->forceCreate(['visit_id' => $visit->id, 'landing_path' => '/games/x', 'created_at' => now()]);

    Livewire::withQueryParams(['tab' => 'logs', 'log' => 'clicks'])->test(TrafficSourcesPage::class)->call('load')
        ->assertSee('Not opened · headless browser · burst from one address');
});

it('shows a click’s spend under a cent in full, and says on hovering where it comes from', function () {
    $api = TrafficSource::factory()->create(['name' => 'ExoClick API', 'link_key' => 'exo', 'spend_mode' => 'api']);
    $click = fn (TrafficSource $source, array $spend): TrafficVisit => TrafficVisit::factory()->create(['source_id' => $source->id, 'arrived_at' => now()->subHour(), ...$spend]);
    $priced = $click($this->source, ['cost' => '0.000759', 'spend' => '0.000759', 'spend_basis' => 'click_cost']);
    $counted = $click($api, ['cost' => '0.000759', 'spend' => '0.00079123', 'spend_basis' => 'api']);
    $estimated = $click($api, ['cost' => '0.000759', 'spend' => '0.000759', 'spend_basis' => 'click_cost', 'spend_estimated' => true]);
    $free = $click($this->source, ['spend' => '0', 'spend_basis' => 'none']);
    $cell = fn (string $shown, string $hint): string => 'title="'.e($hint).'" data-testid="traffic-log-click-spend">'.$shown.'<span class="rg-admin-sr-only">, '.e($hint).'</span>';

    $page = Livewire::withQueryParams(['tab' => 'logs', 'log' => 'clicks'])->test(TrafficSourcesPage::class)->call('load')
        ->assertSeeHtml($cell('$0.000759', 'Price in the click $0.000759'))
        ->assertSeeHtml($cell('$0.000791', 'Price in the click $0.000759 · counted $0.00079123, the network’s bill shared out'))
        ->assertSeeHtml($cell('$0.000759', 'Price in the click $0.000759 · estimate until the network’s bill is shared out'))
        ->assertSeeHtml($cell('$0.0000', 'Not counted'))
        ->assertDontSee('$0.0008');

    expect($page->viewData('spendHints'))->toBe([
        $free->id => 'Not counted',
        $estimated->id => 'Price in the click $0.000759 · estimate until the network’s bill is shared out',
        $counted->id => 'Price in the click $0.000759 · counted $0.00079123, the network’s bill shared out',
        $priced->id => 'Price in the click $0.000759',
    ]);
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

it('says in Logs → Blocks which batches went part of the way, and what the read of the network’s lists found', function () {
    $event = fn (string $kind, bool $ok, bool $partial, string $note, TrafficBlockMethod $method = TrafficBlockMethod::Api, TrafficActor $actor = TrafficActor::NetworkApi) => TrafficBlockEvent::query()->create(['source_id' => $this->source->id, 'kind' => $kind, 'level' => TrafficBlockLevel::Site, 'ref' => '40123', 'ref_count' => 1, 'method' => $method, 'actor' => $actor, 'ok' => $ok, 'partial' => $partial, 'note' => $note, 'occurred_at' => now()]);
    $event('applied', false, true, '1 of 2 campaigns');
    $event('found', true, false, 'On the network’s list, blocked in its panel, not by RateGuru', TrafficBlockMethod::Panel, TrafficActor::NetworkPanel);
    $event('gone', true, false, 'Off every campaign’s list: the block rule leaves it alone for 30 days', TrafficBlockMethod::Panel, TrafficActor::NetworkPanel);
    // Off some campaigns' lists only: still blocked on the others.
    $event('gone', true, true, 'Still on other campaigns’ lists', TrafficBlockMethod::Panel, TrafficActor::NetworkPanel);

    Livewire::withQueryParams(['tab' => 'logs', 'log' => 'blocks'])->test(TrafficSourcesPage::class)->call('load')
        ->assertSeeInOrder(['Taken off some lists in the panel', 'network panel', 'Done', 'Still on other campaigns', 'Taken off in the panel', 'Off every campaign', 'Found in the panel', 'Applied', 'Partly done', '1 of 2 campaigns']);
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
    // The project's settings are read once, by whatever asks first: only what the page reads per visitor is at issue.
    site_name();
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

    // The journey as the page reads it once it is in a browser: the visitor itself, not the first example or a list to choose from —
    // and not the other browser, whose label reads the same: only the visitor's click landed on /games/x.
    $journey = Livewire::withQueryParams($query)->test(GrowthAnalyticsPage::class)->call('load')
        ->assertSet('tab', 'journeys')
        ->assertSet('who', $who)
        ->viewData('data');

    expect($journey['state'])->toBe('journey')
        ->and($journey['journey']['label'])->toBe($id.' · not registered')
        ->and(collect($journey['journey']['entries'])->pluck('meta')->filter(fn (?string $meta): bool => str_contains((string) $meta, '/games/x'))->all())->toHaveCount(1);

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

it('splits the sign-ups of a network with no zones by site when split by zone, as Sites & zones lists it', function () {
    $signedUp = ['source_id' => $this->source->id, 'zone_ref' => null, 'arrived_at' => '2026-11-16 10:00:00', 'network_day' => '2026-11-16', 'signup_at' => '2026-11-16 10:05:00', 'signup_method' => 'email', 'account_at' => '2026-11-16 10:08:00', 'signup_after_seconds' => 300, 'account_after_seconds' => 480];
    TrafficVisit::factory()->count(2)->create([...$signedUp, 'site_ref' => '3567733']);
    TrafficVisit::factory()->create([...$signedUp, 'site_ref' => '5230973']);
    // A zone the network does name stays a zone.
    TrafficVisit::factory()->create([...$signedUp, 'site_ref' => '5230973', 'zone_ref' => '70001']);

    $card = Livewire::withQueryParams(['signups' => 'zone'])->test(TrafficSourcesPage::class)->call('load')
        ->assertSee('site 3567733')
        ->assertSee('no zones, by site')
        ->assertDontSee('No ID')
        ->viewData('signups');

    // The biggest first; the two of one sign-up each in whichever order the database puts a missing zone.
    $rows = collect($card['rows'])->keyBy('label');
    expect($card['rows'][0]['label'])->toBe('site 3567733')
        ->and($rows->keys()->all())->toEqualCanonicalizing(['site 3567733', 'site 5230973', 'zone 70001'])
        ->and($rows['site 3567733'])->toMatchArray(['sub' => 'PopAds main · no zones, by site', 'mono' => true, 'find' => ['source' => $this->source->id, 'level' => 'site', 'ref' => '3567733']])
        ->and($rows['zone 70001'])->toMatchArray(['sub' => 'PopAds main', 'find' => ['source' => $this->source->id, 'level' => 'zone', 'ref' => '70001']])
        ->and($card['total']->signedUp)->toBe(4);
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
