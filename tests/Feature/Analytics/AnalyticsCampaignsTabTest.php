<?php

use App\Enums\GameStatus;
use App\Enums\TrafficConnectionStatus;
use App\Enums\TrafficSourceStatus;
use App\Enums\TrafficSpendMode;
use App\Filament\Pages\Analytics\CampaignsTab;
use App\Filament\Pages\GrowthAnalyticsPage;
use App\Filament\Pages\TrafficSourcesPage;
use App\Models\Game;
use App\Models\TrafficApiConnection;
use App\Models\TrafficCampaign;
use App\Models\TrafficSource;
use App\Models\TrafficSpendDay;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * Analytics → Campaigns: where spend comes from, the paid sources, and the
 * tree of campaigns — a paid source's by name, a tagged one by its tag —
 * their creatives and the games played, each row with its spend (every
 * click, its page run or not, a campaign's the spend none of its visits
 * took too), whether any was estimated, its visitors, starts, completions,
 * accounts and activated users with rates of new visitors, "—" below the
 * denominator a row needs, “Too early” while no account is old enough, and
 * what each cost; the best creative by activation; a game's row leading to
 * the Games tab. The biggest campaign and its biggest creative open to begin
 * with, the creative the address names instead, marked; any row toggles.
 * Every filter of the page applies to the tree, the period and device to the
 * paid sources. Nobody matching is said once; the page reads no visit until
 * it is in a browser, and the tab a bounded number of queries.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00'));
    $this->actingAs(User::factory()->admin()->create());
    $this->game = Game::factory()->status(GameStatus::Live)->create(['title' => 'Trust Your Taste']);
    $this->exo = TrafficSource::factory()->create(['name' => 'ExoClick tests']);
    $this->campaign = TrafficCampaign::factory()->create(['source_id' => $this->exo->id, 'name' => 'US popunder', 'external_id' => '881204']);
});

/** The open Campaigns tab's figures, read as the page reads them once it is in a browser. */
function analyticsCampaigns(array $query = []): array
{
    return Livewire::withQueryParams(['tab' => 'campaigns', ...$query])->test(GrowthAnalyticsPage::class)->call('load')->viewData('data');
}

/** The tree's rows as text: indented by level, the name, what is under it, the source, then each cell's figure with its line. */
function campaignRows(array $data): array
{
    return array_map(fn (array $row): string => str_repeat('  ', $row['level']).$row['name'].' | '.($row['sub'] ?? '-').' | '.$row['source']['text'].' | '
        .implode(' | ', array_map(fn (array $cell): string => $cell['value'].($cell['sub'] === null ? '' : ' ('.$cell['sub'].')'), $row['cells'])), $data['rows']);
}

/**
 * Forty people through the paid campaign on 05 Nov — thirty by banner-1,
 * playing Trust Your Taste or 60 Second Blitz, ten by banner-2 whose spend
 * was estimated — ten cents a click, two more of banner-1's clicks never
 * running their page; and thirty-five through the tagged campaign “spring”,
 * from Reddit and Facebook, which came before the period too.
 */
function campaignFixture(Game $game, TrafficCampaign $campaign): Game
{
    $blitz = Game::factory()->status(GameStatus::Live)->create(['title' => '60 Second Blitz']);
    $day = ['network_day' => '2026-11-05', 'arrived_at' => '2026-11-05 10:00:00'];

    foreach (range(1, 40) as $n) {
        analyticsClick('paid-'.$n, $campaign, [
            ...$day,
            'creative_key' => $n <= 30 ? 'banner-1' : 'banner-2',
            'game_id' => $n <= 20 || $n > 30 ? $game->id : $blitz->id,
            'spend' => '0.10',
            'spend_estimated' => $n > 30,
            'started_at' => $n <= 36 ? '2026-11-05 10:01:00' : null,
            'completed_at' => $n <= 27 ? '2026-11-05 10:03:00' : null,
            'signup_at' => $n <= 4 ? '2026-11-05 11:00:00' : null,
            'account_at' => $n <= 4 ? '2026-11-05 11:00:00' : null,
            'activated_at' => $n === 1 ? '2026-11-07 09:00:00' : null,
        ]);
    }

    foreach (range(1, 2) as $n) {
        analyticsClick('never-'.$n, $campaign, [...$day, 'creative_key' => 'banner-1', 'game_id' => $game->id, 'spend' => '0.10', 'opened_at' => null]);
    }

    foreach (range(1, 35) as $n) {
        analyticsVisit('spring-'.$n, ['network_day' => '2026-11-10', 'arrived_at' => '2026-11-10 10:00:00', 'source_key' => $n <= 30 ? 'reddit:organic' : 'facebook:organic', 'campaign_key' => 'tag:spring']);
    }

    analyticsVisit('spring-before', ['network_day' => '2026-10-10', 'arrived_at' => '2026-10-10 10:00:00', 'source_key' => 'reddit:organic', 'campaign_key' => 'tag:spring']);

    // Visitors no campaign brought: no row of their own.
    foreach (range(1, 10) as $n) {
        analyticsVisit('direct-'.$n);
    }

    return $blitz;
}

it('lists paid and tagged campaigns, the most people first, the biggest open down to the games its biggest creative was played on', function () {
    campaignFixture($this->game, $this->campaign);
    $exo = 'ExoClick tests · paid_ads';

    $data = analyticsCampaigns();

    expect(campaignRows($data))->toBe([
        // Every click counts in the spend, its page run or not: 42 clicks, ten cents each; ten of them estimated.
        'US popunder | ID 881204 · since 05 Nov | '.$exo.' | $4.20 (estimated) | 40 | $0.105 | 36 (90.0% of visitors) | 75.0% | 4 (10.0% of new visitors) | $1.05 | 1 (2.5% of new visitors) | $4.20',
        '  banner-1 | - | Creative ID | $3.20 | 30 | $0.107 | 30 (100.0% of visitors) | 90.0% | 4 (13.3% of new visitors) | $0.800 | 1 (3.3% of new visitors) | $3.20',
        // A game's row: what the clicks that landed on it cost.
        '    Trust Your Taste | Open game analytics |  | $2.20 | 20 | $0.110 | 20 | — | 4 | $0.550 | 1 | $2.20',
        '    60 Second Blitz | Open game analytics |  | $1.00 | 10 | $0.100 | 10 | — | 0 | — | 0 | —',
        '  banner-2 | - | Creative ID | $1.00 (estimated) | 10 | $0.100 | 6 | — | 0 | — | 0 | —',
        // A campaign named by its tag costs nothing here; it came before the period too.
        'spring | utm_campaign · Always on | Reddit · organic_social | Unpaid | 35 | — | 0 (0.0% of visitors) | — | 0 (0.0% of new visitors) | — | 0 (0.0% of new visitors) | —',
    ])
        ->and($data['rows'][0]['cells'][0]['title'])->toBe('$1.00 of it estimated')
        ->and($data['rows'][5]['source']['more'])->toBe('and 1 more source')
        ->and(array_column($data['rows'], 'open'))->toBe([true, true, null, null, false, false])
        ->and($data['rows'][1]['cells'][4]['title'])->toBe('27 of 30 starters')
        ->and($data['description'])->toBe('Click a campaign to see its creatives, then a creative to see its games. Last 30 days.')
        ->and($data['sorts'])->toBe(CampaignsTab::SORTS)
        ->and($data['foot'])->toBe('A paid campaign and its creative come from the network’s link; a tagged one from utm_campaign, its creative from utm_content, else utm_term. '
            .'Click a game row to open its analytics. Registration and activation use new visitors; a rate shows “—” until the number it divides by reaches 30. '
            .'Ad networks count clicks differently, so their numbers won’t match these visitors exactly.');
});

it('leads a game’s row to the game on the Games tab, of the same days and device, and an archived game’s nowhere', function () {
    $blitz = campaignFixture($this->game, $this->campaign);
    $blitz->forceFill(['status' => GameStatus::Archived])->save();

    $games = collect(analyticsCampaigns(['period' => '90d', 'device' => 'mobile', 'source' => 'paid:'.$this->exo->id])['rows'])->where('kind', 'game')->values();

    expect($games->pluck('url')->all())->toBe([GrowthAnalyticsPage::getUrl(['tab' => 'games', 'game' => $this->game->id, 'period' => '90d', 'device' => 'mobile']), null])
        ->and($games->pluck('sub')->all())->toBe(['Open game analytics', 'Archived']);

    Livewire::withQueryParams(['tab' => 'campaigns', 'period' => '90d'])->test(GrowthAnalyticsPage::class)->call('load')
        ->assertSeeHtml('href="'.e(GrowthAnalyticsPage::getUrl(['tab' => 'games', 'game' => $this->game->id, 'period' => '90d'])).'"');
});

it('counts in a campaign the spend none of its visits took, on a row of its own, and leaves it out while a device is chosen', function () {
    campaignFixture($this->game, $this->campaign);
    $quiet = TrafficCampaign::factory()->create(['source_id' => $this->exo->id, 'name' => 'EU night']);
    $spendDay = fn (?TrafficCampaign $campaign, string $amount, string $allocated = '0') => TrafficSpendDay::factory()->create([
        'source_id' => $this->exo->id, 'campaign_id' => $campaign?->id, 'network_day' => '2026-11-06', 'amount' => $amount, 'allocated_amount' => $allocated, 'counted' => true,
    ]);
    $spendDay($this->campaign, '2.50', '0.50');
    // A campaign nobody came through here — its one click a crawler's — and the source's own spend, which has no row of the tree.
    $spendDay($quiet, '1.25');
    $spendDay(null, '3.00');
    analyticsClick('crawler', $quiet, ['spend' => '0.30', 'opened_at' => null]);

    $rows = collect(analyticsCampaigns()['rows']);
    $idle = $rows->where('kind', 'idle')->values();

    expect($rows->first()['cells'][0]['value'])->toBe('$6.20')
        ->and($rows->first()['cells'][6]['value'])->toBe('$1.55')
        ->and($idle->map(fn (array $row): string => $row['level'].' '.$row['name'].' | '.$row['sub'].' | '.$row['cells'][0]['value'])->all())->toBe([
            '1 Spend with no visits | Not shared over its visits yet, or on days without one | $2.00',
            '0 Spend with no visits | Campaigns no visitor here came through | $1.55',
        ])
        // The creatives' rows come first under the campaign.
        ->and($rows->search(fn (array $row): bool => $row['kind'] === 'idle'))->toBe(5);

    // That spend has no device: a device chosen leaves it out, and says so — the crawler's click had one.
    $mobile = analyticsCampaigns(['device' => 'mobile']);

    expect($mobile['rows'][0]['cells'][0]['value'])->toBe('$4.20')
        ->and(collect($mobile['rows'])->where('kind', 'idle')->map(fn (array $row): string => $row['level'].' '.$row['cells'][0]['value'])->values()->all())->toBe(['0 $0.30'])
        ->and($mobile['foot'])->toContain('Spend no visit took has no device, so it is left out while one is chosen.');

    // A campaign named by its tag takes none of it, a paid one only its own.
    expect(collect(analyticsCampaigns(['campaign' => 'tag:spring'])['rows'])->where('kind', 'idle'))->toBeEmpty()
        ->and(collect(analyticsCampaigns(['campaign' => 'paid:'.$this->campaign->id])['rows'])->pluck('kind')->filter(fn (string $kind): bool => $kind === 'idle')->count())->toBe(1);
});

it('marks the creative with the best activation rate of those with enough visitors, once two have them', function () {
    config(['analytics.best_creative_min_visitors' => 30]);
    // By its creative: how many people, how many of them activated an account old enough.
    foreach (['' => [50, 10], 'a' => [40, 2], 'b' => [35, 3], 'c' => [20, 5]] as $creative => [$people, $activated]) {
        foreach (range(1, $people) as $n) {
            analyticsClick("best-{$creative}-{$n}", $this->campaign, [
                'creative_key' => $creative === '' ? null : $creative,
                'signup_at' => $n <= $activated ? '2026-11-05 11:00:00' : null,
                'account_at' => $n <= $activated ? '2026-11-05 11:00:00' : null,
                'activated_at' => $n <= $activated ? '2026-11-07 09:00:00' : null,
            ]);
        }
    }

    $best = fn (): array => collect(analyticsCampaigns()['rows'])->where('best', true)->pluck('name')->all();

    // No creative is none, and too few visitors can't be the best however many activated.
    expect($best())->toBe(['b']);

    Livewire::withQueryParams(['tab' => 'campaigns'])->test(GrowthAnalyticsPage::class)->call('load')->assertSee('Best activation rate');

    // Only one creative with enough visitors: there is nothing to be best among.
    config(['analytics.best_creative_min_visitors' => 36]);
    expect($best())->toBe([]);
});

it('says activation is too early while none of a row’s accounts is old enough, and from when accounts can still activate', function () {
    foreach (range(1, 40) as $n) {
        analyticsClick('fresh-'.$n, $this->campaign, $n <= 4 ? ['signup_at' => '2026-11-14 10:00:00', 'account_at' => '2026-11-14 10:00:00'] : []);
    }

    $data = analyticsCampaigns();
    $activated = $data['rows'][0]['cells'][7];

    expect($activated)->toMatchArray(['value' => '0', 'sub' => 'Too early', 'title' => '4 accounts, none old enough yet'])
        ->and($data['foot'])->toContain('Activation needs 7 days after signup: “Too early” marks a row none of whose accounts is that old yet. Accounts from 11 Nov on can still activate.');
});

it('sorts the campaigns and their creatives by a rate of new visitors or by what an account cost, offering cost only with spend', function () {
    $cheap = TrafficCampaign::factory()->create(['source_id' => $this->exo->id, 'name' => 'Cheap']);
    $account = fn (int $n, int $accounts): array => $n <= $accounts ? ['signup_at' => '2026-11-05 11:00:00', 'account_at' => '2026-11-05 11:00:00'] : [];

    foreach (range(1, 40) as $n) {
        analyticsClick('dear-'.$n, $this->campaign, ['spend' => '0.50', ...$account($n, 4)]);
    }

    foreach (range(1, 31) as $n) {
        analyticsClick('cheap-'.$n, $cheap, ['spend' => '0.10', ...$account($n, 2)]);
    }

    // A tagged campaign whose new visitors are too few for a rate: last whatever the rate sorted by.
    foreach (range(1, 45) as $n) {
        analyticsVisit('tag-'.$n, ['campaign_key' => 'tag:launch', 'known' => $n > 10]);
    }

    $order = fn (string $sort): array => collect(analyticsCampaigns(['csort' => $sort])['rows'])->where('level', 0)->pluck('name')->all();

    expect($order('visitors'))->toBe(['launch', 'US popunder', 'Cheap'])
        // 10.0% of 40 against 6.5% of 31.
        ->and($order('registration'))->toBe(['US popunder', 'Cheap', 'launch'])
        // $1.55 an account against $5.00.
        ->and($order('cost'))->toBe(['Cheap', 'US popunder', 'launch']);

    // Nothing spent: no sorting by cost, and an address asking for it sorts by people.
    DB::table('traffic_visits')->whereNotNull('source_id')->delete();

    $unpaid = analyticsCampaigns(['csort' => 'cost']);

    expect(array_keys($unpaid['sorts']))->toBe(['visitors', 'registration', 'activation'])
        ->and($unpaid['sort'])->toBe('visitors');
});

it('opens or closes any row, keeping only the latest few toggled, and opens and marks the creative the address names', function () {
    campaignFixture($this->game, $this->campaign);
    $paid = 'paid:'.$this->campaign->id;
    $names = fn ($page): array => collect($page->viewData('data')['rows'])->map(fn (array $row): string => str_repeat('  ', $row['level']).$row['name'].($row['marked'] ? ' *' : ''))->all();

    $page = Livewire::withQueryParams(['tab' => 'campaigns'])->test(GrowthAnalyticsPage::class)->call('load')
        ->call('toggleCampaignRow', $paid, 'banner-1')
        ->call('toggleCampaignRow', 'tag:spring');

    expect($names($page))->toBe(['US popunder', '  banner-1', '  banner-2', 'spring', '  (no creative)']);

    $page->call('toggleCampaignRow', $paid, 'banner-2')->call('toggleCampaignRow', $paid);
    expect($names($page))->toBe(['US popunder', 'spring', '  (no creative)']);

    // What no row could name changes nothing.
    $page->call('toggleCampaignRow', 'nope')->call('toggleCampaignRow', $paid, "bad\x01creative")->call('toggleCampaignRow', $paid, ['banner-1']);
    expect($page->get('toggled'))->toHaveCount(4);

    foreach (range(1, CampaignsTab::MOST_TOGGLED + 2) as $n) {
        $page->call('toggleCampaignRow', 'tag:other-'.$n);
    }

    expect($page->get('toggled'))->toHaveCount(CampaignsTab::MOST_TOGGLED)
        ->and(collect($page->get('toggled'))->last())->toBe(CampaignsTab::node('tag:other-'.(CampaignsTab::MOST_TOGGLED + 2)));

    // Leaving the tab forgets them, and the browser can't set them.
    $page->set('tab', 'overview')->assertSet('toggled', []);
    expect(fn () => $page->set('toggled', ['x']))->toThrow(Exception::class);

    // The address's creative opens in place of the biggest, it and its campaign marked.
    $marked = Livewire::withQueryParams(['tab' => 'campaigns', 'campaign' => $paid, 'creative' => 'banner-2'])->test(GrowthAnalyticsPage::class)->call('load');

    expect($names($marked))->toBe(['US popunder *', '  banner-1', '  banner-2 *', '    Trust Your Taste'])
        ->and(collect($marked->viewData('data')['rows'])->where('kind', 'creative')->pluck('open')->all())->toBe([false, true]);

    // Another campaign drops it; one the campaign doesn't have opens the biggest, only the campaign marked.
    $marked->set('campaign', 'tag:spring')->assertSet('creative', '');
    expect($names(Livewire::withQueryParams(['tab' => 'campaigns', 'campaign' => $paid, 'creative' => 'banner-9'])->test(GrowthAnalyticsPage::class)->call('load')))
        ->toBe(['US popunder *', '  banner-1', '    Trust Your Taste', '    60 Second Blitz', '  banner-2']);

    // A network's creative id of digits alone, read by the address as a number, is a creative too.
    analyticsClick('numbered', $this->campaign, ['creative_key' => '6']);
    expect(collect(Livewire::withQueryParams(['tab' => 'campaigns', 'campaign' => $paid, 'creative' => 6])->test(GrowthAnalyticsPage::class)->assertSet('creative', '6')->call('load')->viewData('data')['rows'])
        ->where('marked', true)->pluck('name')->all())->toBe(['US popunder', '6']);

    // A creative is read only with a campaign, and only on this tab.
    Livewire::withQueryParams(['tab' => 'campaigns', 'creative' => 'banner-2'])->test(GrowthAnalyticsPage::class)->assertSet('creative', '');
    Livewire::withQueryParams(['tab' => 'overview', 'campaign' => $paid, 'creative' => 'banner-2', 'csort' => 'cost'])->test(GrowthAnalyticsPage::class)->assertSet('creative', '')->assertSet('csort', 'visitors');
    Livewire::withQueryParams(['tab' => 'campaigns', 'csort' => 'sideways', 'campaign' => $paid, 'creative' => "x\x01"])->test(GrowthAnalyticsPage::class)->assertSet('csort', 'visitors')->assertSet('creative', '');
});

it('answers only an administrator who may manage traffic sources, every request asking again', function () {
    campaignFixture($this->game, $this->campaign);
    $page = Livewire::withQueryParams(['tab' => 'campaigns'])->test(GrowthAnalyticsPage::class)->call('load');

    $this->actingAs(User::factory()->moderator()->create());

    $page->call('toggleCampaignRow', 'tag:spring')->assertForbidden();
    $this->get(GrowthAnalyticsPage::getUrl(['tab' => 'campaigns']))->assertForbidden();
});

it('says where spend comes from: each way the sources count it, with the campaigns of their own, and what the networks’ APIs report', function () {
    TrafficSource::factory()->create(['name' => 'JuicyAds banners', 'spend_mode' => TrafficSpendMode::Rate]);
    TrafficSource::factory()->placement()->create(['name' => 'Cat blog sidebar']);
    TrafficSource::factory()->create(['name' => 'Old network', 'status' => TrafficSourceStatus::Ended]);
    TrafficCampaign::factory()->count(2)->create(['source_id' => $this->exo->id, 'spend_mode' => TrafficSpendMode::Price]);
    analyticsVisit('ann');

    $origin = fn (): array => analyticsCampaigns()['spendOrigin'];

    expect(array_map(fn (array $line): string => $line['mode'].' | '.$line['who'], $origin()['lines']))->toBe([
        // An ended source counts nothing any more.
        'Price in each click | ExoClick tests',
        'Your rate | JuicyAds banners',
        'Campaign price | 2 campaigns of their own',
        'Fixed price | Cat blog sidebar',
    ])
        ->and($origin()['lines'][0]['note'])->toBe('The price the network puts in each click’s link.')
        ->and($origin()['band'])->toMatchArray(['tone' => 'neutral', 'icon' => 'unplug', 'title' => 'No network API connected.'])
        ->and($origin()['url'])->toBe(TrafficSourcesPage::getUrl(['tab' => 'spend']));

    $connection = TrafficApiConnection::factory()->create(['source_id' => $this->exo->id]);
    expect($origin()['band'])->toMatchArray(['tone' => 'info', 'icon' => 'plug', 'title' => 'Network API connected.'])
        ->and($origin()['band']['text'])->toStartWith('ExoClick tests reports its spend');

    $connection->forceFill(['status' => TrafficConnectionStatus::NeedsAttention])->save();
    expect($origin()['band'])->toMatchArray(['tone' => 'warning', 'title' => '1 API connection needs attention.']);

    Livewire::withQueryParams(['tab' => 'campaigns'])->test(GrowthAnalyticsPage::class)->call('load')
        ->assertSee('Where spend comes from')
        ->assertSee('1 API connection needs attention.');
});

it('lists the paid sources with the visitors and spend of the period and device, leading to their settings and filtering the page', function () {
    campaignFixture($this->game, $this->campaign);
    $banner = TrafficSource::factory()->placement()->create(['name' => 'Cat blog sidebar']);
    TrafficSource::factory()->create(['name' => 'Old network', 'status' => TrafficSourceStatus::Ended]);
    $quiet = TrafficSource::factory()->create(['name' => 'PopAds main', 'status' => TrafficSourceStatus::Paused]);
    TrafficSpendDay::factory()->create(['source_id' => $banner->id, 'network_day' => '2026-11-06', 'amount' => '3.00', 'counted' => true]);
    analyticsVisit('desk', ['device' => 'desktop', 'source_id' => $banner->id, 'source_key' => 'paid:'.$banner->id, 'spend' => '0.40']);

    $line = fn (array $row): string => $row['name'].' | '.$row['medium'].' | '.$row['status']['label'].' | '.$row['note'].' | '.$row['people'].' | '.($row['spend'] ?? '-');
    $paid = analyticsCampaigns()['paidSources'];

    // The most people first; an ended source with nothing in the period isn't listed.
    expect(array_map($line, $paid['rows']))->toBe([
        'ExoClick tests | paid_ads | Active | Ad network · spend: price in each click · 1 campaign | 40 visitors | $4.20 spent',
        'Cat blog sidebar | paid_ads | Active | Banner or link · spend: fixed price | 1 visitor | $3.40 spent',
        'PopAds main | paid_ads | Paused | Ad network · spend: price in each click · 0 campaigns | No visitors | -',
    ])
        ->and($paid['rows'][0]['settings'])->toBe(TrafficSourcesPage::getUrl(['tab' => 'sources', 'edit' => $this->exo->id]))
        ->and($paid['description'])->toBe('The ad networks and placements set up under Traffic sources, with the visitors each brought in 20 Oct — 18 Nov 2026. Click one to filter the page by it.');

    // The source chosen is marked, and every source still counted; a device narrows them, leaving out the spend no visit took.
    $chosen = analyticsCampaigns(['source' => 'paid:'.$this->exo->id])['paidSources']['rows'];
    $desktop = analyticsCampaigns(['device' => 'desktop'])['paidSources'];

    expect(array_column($chosen, 'selected'))->toBe([true, false, false])
        ->and(array_column($chosen, 'people'))->toBe(['40 visitors', '1 visitor', 'No visitors'])
        ->and(array_map($line, $desktop['rows']))->toBe([
            'Cat blog sidebar | paid_ads | Active | Banner or link · spend: fixed price | 1 visitor | $0.40 spent',
            'ExoClick tests | paid_ads | Active | Ad network · spend: price in each click · 1 campaign | No visitors | -',
            'PopAds main | paid_ads | Paused | Ad network · spend: price in each click · 0 campaigns | No visitors | -',
        ])
        ->and($desktop['description'])->toContain('in 20 Oct — 18 Nov 2026 on desktop.');

    // A click filters the page by the source, a second click by every source again.
    Livewire::withQueryParams(['tab' => 'campaigns'])->test(GrowthAnalyticsPage::class)->call('load')
        ->assertSeeHtml('pickSource(\'paid:'.$quiet->id.'\')')
        ->call('pickSource', 'paid:'.$quiet->id)->assertSet('source', 'paid:'.$quiet->id)
        ->call('pickSource', 'paid:'.$quiet->id)->assertSet('source', '');
});

it('reads every filter of the page in the tree, says when no campaign matches, and says so once when nobody does', function () {
    campaignFixture($this->game, $this->campaign);
    analyticsVisit('desk', ['device' => 'desktop', 'campaign_key' => 'tag:desk', 'source_key' => 'google:organic']);

    $campaigns = fn (array $query): array => collect(analyticsCampaigns($query)['rows'])->where('level', 0)->map(fn (array $row): string => $row['name'].' '.$row['cells'][1]['value'])->values()->all();

    expect($campaigns([]))->toBe(['US popunder 40', 'spring 35', 'desk 1'])
        ->and($campaigns(['device' => 'desktop']))->toBe(['desk 1'])
        ->and($campaigns(['source' => 'facebook:organic']))->toBe(['spring 5'])
        ->and($campaigns(['source' => 'reddit:organic', 'campaign' => 'tag:spring']))->toBe(['spring 30'])
        ->and($campaigns(['from' => '2026-10-05', 'to' => '2026-10-15']))->toBe(['spring 1']);

    // People, but no campaign brought them.
    $none = Livewire::withQueryParams(['tab' => 'campaigns', 'source' => 'direct'])->test(GrowthAnalyticsPage::class)->call('load');

    expect($none->viewData('data')['rows'])->toBe([]);
    $none->assertSee('No campaigns match these filters.')->assertSee('Paid sources');

    $empty = Livewire::withQueryParams(['tab' => 'campaigns', 'source' => 'bing:organic', 'device' => 'mobile'])->test(GrowthAnalyticsPage::class)->call('load');

    expect($empty->viewData('data'))->toBe(['empty' => true, 'range' => '20 Oct — 18 Nov 2026', 'filtered' => true]);

    $empty->assertSee('No visitors for these filters')
        ->assertDontSee('Campaign performance')
        ->assertDontSee('Where spend comes from')
        ->call('clearFilters')
        ->assertSee('Campaign performance');
});

it('reads no visit until the page is in a browser, and the tab in a bounded number of queries, the campaigns past the first rows together', function () {
    $queries = function (array $query, array $toggles = []): int {
        $page = Livewire::withQueryParams(['tab' => 'campaigns', ...$query])->test(GrowthAnalyticsPage::class)->call('load');

        foreach ($toggles as $toggle) {
            $page->call('toggleCampaignRow', ...$toggle);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $page->call('$refresh');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    campaignFixture($this->game, $this->campaign);
    $few = $queries([]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get(GrowthAnalyticsPage::getUrl(['tab' => 'campaigns']))->assertOk()->assertDontSee('Campaign performance');
    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $query): bool => str_contains($query, 'traffic_visits'))->values()->all())->toBe([]);
    DB::disableQueryLog();

    // Many campaigns of many sources, each with creatives and games of its own.
    foreach (range(1, CampaignsTab::CAMPAIGNS + 2) as $n) {
        $source = TrafficSource::factory()->create();
        $campaign = TrafficCampaign::factory()->create(['source_id' => $source->id]);
        $game = Game::factory()->status(GameStatus::Live)->create();

        foreach (range(1, 2) as $visitor) {
            analyticsClick("many-{$n}-{$visitor}", $campaign, ['creative_key' => 'c'.$visitor, 'game_id' => $game->id, 'spend' => '0.05']);
        }
    }

    $data = analyticsCampaigns();
    $many = $queries([]);
    $narrowed = $queries(['source' => 'paid:'.$this->exo->id, 'device' => 'mobile', 'campaign' => 'paid:'.$this->campaign->id]);
    $opened = $queries([], [['tag:spring'], ['paid:'.$this->campaign->id, 'banner-2']]);

    expect(collect($data['rows'])->where('level', 0)->count())->toBe(CampaignsTab::CAMPAIGNS + 1)
        ->and(collect($data['rows'])->last())->toMatchArray(['name' => 'Other campaigns', 'sub' => 'Every campaign past the first 25, each visitor once', 'kind' => 'rest'])
        // The shell, the tree's levels with their spend, sources, names and first days, the games, the spend origin and paid sources: never one per row.
        ->and($few)->toBeLessThanOrEqual(30)
        ->and($many)->toBeLessThanOrEqual(30)
        ->and($narrowed)->toBeLessThanOrEqual(30)
        // Everyone else's row is one more read, and each row opened one or two.
        ->and($many - $few)->toBeLessThanOrEqual(2)
        ->and($opened - $many)->toBeLessThanOrEqual(3);
});

it('keeps the spend no visit took yet of a campaign past the listed ones in everyone else’s row, not as idle spend', function () {
    // More campaigns with visitors than the tree lists: the last, with the fewest, sits in "Other campaigns".
    $last = null;

    foreach (range(1, CampaignsTab::CAMPAIGNS + 1) as $n) {
        $source = TrafficSource::factory()->create();
        $last = TrafficCampaign::factory()->create(['source_id' => $source->id]);

        foreach (range(1, $n === CampaignsTab::CAMPAIGNS + 1 ? 1 : 3) as $visitor) {
            analyticsClick("listed-{$n}-{$visitor}", $last, ['spend' => '0.10']);
        }
    }

    TrafficSpendDay::factory()->create(['source_id' => $last->source_id, 'campaign_id' => $last->id, 'network_day' => '2026-11-06', 'amount' => '5.00', 'allocated_amount' => '0', 'counted' => true]);

    $rows = collect(analyticsCampaigns()['rows']);

    expect($rows->firstWhere('name', 'Other campaigns')['cells'][0]['value'])->toBe('$5.10')
        ->and($rows->where('kind', 'idle')->where('level', 0)->all())->toBe([]);
});
