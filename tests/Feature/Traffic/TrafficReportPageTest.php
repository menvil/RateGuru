<?php

use App\Enums\TrafficBlockLevel;
use App\Enums\TrafficChannel;
use App\Filament\Pages\TrafficSourcesPage;
use App\Models\Game;
use App\Models\TrafficBlock;
use App\Models\TrafficCampaign;
use App\Models\TrafficSetting;
use App\Models\TrafficSource;
use App\Models\TrafficVisit;
use App\Models\User;
use Carbon\CarbonImmutable;
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
    $this->source = TrafficSource::factory()->create(['name' => 'PopAds main']);
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
    TrafficVisit::factory()->unpaid(TrafficChannel::Search)->create(['arrived_at' => '2026-11-16 10:00:00', 'network_day' => '2026-11-16', 'opened_at' => now()]);

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

it('narrows every figure by the type of traffic, the game and the device', function () {
    $game = Game::factory()->create(['title' => 'Taste Test']);
    siteClicks($this->source, '80001', 2, 2, ['game_id' => $game->id]);
    TrafficVisit::factory()->unpaid(TrafficChannel::Direct)->create(['arrived_at' => '2026-11-16 10:00:00', 'network_day' => '2026-11-16', 'opened_at' => now(), 'device' => 'desktop']);

    $visits = fn (array $query): int => Livewire::withQueryParams($query)->test(TrafficSourcesPage::class)->call('load')->viewData('total')->visits;

    expect($visits([]))->toBe(3)
        ->and($visits(['type' => 'unpaid']))->toBe(1)
        ->and($visits(['type' => 'network']))->toBe(2)
        ->and($visits(['game' => (string) $game->id]))->toBe(2)
        ->and($visits(['device' => 'desktop']))->toBe(1);
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
        ->assertSee('Cat blog sidebar');

    $page->set('stage', 'scale')->assertSee('TYT push')->assertDontSee('New campaign 881377')
        ->set('stage', 'new')->assertSee('New campaign 881377')
        ->call('openCampaign', $new->id)->assertSet('camp', (string) $new->id)
        ->call('reportFor', $banner->id)->assertSet('tab', 'report')->assertSet('source', (string) $banner->id);
});
