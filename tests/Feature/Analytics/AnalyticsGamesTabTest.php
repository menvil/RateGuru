<?php

use App\Enums\GameAudience;
use App\Enums\GameEventType;
use App\Enums\GameStatus;
use App\Enums\TrafficChannel;
use App\Enums\TrafficDevice;
use App\Filament\Pages\GrowthAnalyticsPage;
use App\Filament\Resources\Games\Pages\GameAnalytics;
use App\Models\Game;
use App\Models\GameAttempt;
use App\Models\GameEvent;
use App\Models\GameVersion;
use App\Models\ProjectSettings;
use App\Models\TrafficSource;
use App\Models\TrafficVisit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * Analytics → Games: a game's own analytics under Growth → Analytics — the
 * game chosen among those not archived, the address's or else the first;
 * the page's period and device with the game's own filters, all in the
 * address and the game's alone to this tab; the very cards and figures of
 * the game's Analytics tab for the same game, days and device; the source
 * and campaign filters said not to apply, and nothing said about recorded
 * visits; no game, and a game with no version yet; no visit read before the
 * page is in a browser, and a bounded number of queries.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00'));
    // The figures as they are at each read: the read cache has tests of its own (AnalyticsReadCacheTest).
    config(['analytics.read_cache_minutes' => 0]);
    ProjectSettings::factory()->create();
    $this->draft = publishableGameDraft(1);
    $this->actingAs($this->draft['admin']);
});

/** The open Games tab, read as the page reads it once it is in a browser. */
function analyticsGames(array $query = []): mixed
{
    return Livewire::withQueryParams(['tab' => 'games', ...$query])->test(GrowthAnalyticsPage::class)->call('load');
}

/**
 * A live game two guests opened from a paid network, a German phone that
 * played and signed up and a French desktop that only looked, a third who
 * played on no recorded visit, a search visitor of the week before, and a
 * logged-in player who played twice.
 */
function playedGame(array $draft): GameVersion
{
    $version = liveGame($draft);
    $game = $draft['game'];
    $network = TrafficSource::factory()->create(['name' => 'PopAds main']);
    $visit = fn (string $guest, TrafficDevice $device, string $country, array $state = []) => TrafficVisit::factory()->create([
        'game_id' => $game->id, 'version_id' => $version->id, 'source_id' => $network->id, 'visitor_hash' => hash('sha256', $guest),
        'device' => $device, 'country' => $country, 'opened_at' => now(), ...$state,
    ]);
    $german = $visit('guest-a', TrafficDevice::Mobile, 'DE', ['started_at' => now(), 'signup_at' => now(), 'account_at' => now(), 'spend' => '0.5', 'value_real' => '0.5']);
    $visit('guest-b', TrafficDevice::Desktop, 'FR', ['spend' => '0.01']);
    TrafficVisit::factory()->unpaid(TrafficChannel::Search)->create([
        'game_id' => $game->id, 'version_id' => $version->id, 'opened_at' => now()->subDays(8), 'arrived_at' => now()->subDays(8), 'network_day' => now()->subDays(8)->toDateString(),
    ]);

    foreach (['guest-a', 'guest-b', 'guest-c'] as $guest) {
        (new GameEvent)->forceFill(['game_id' => $game->id, 'version_id' => $version->id, 'audience' => GameAudience::Anonymous, 'visitor_hash' => hash('sha256', $guest), 'type' => GameEventType::View, 'occurred_at' => now()])->save();
    }

    GameAttempt::factory()->create(['version_id' => $version->id, 'visitor_hash' => hash('sha256', 'guest-a'), 'traffic_visit_id' => $german->id]);
    GameAttempt::factory()->create(['version_id' => $version->id, 'visitor_hash' => hash('sha256', 'guest-c')]);
    GameAttempt::factory()->count(2)->create(['version_id' => $version->id, 'audience' => GameAudience::Authenticated, 'user_id' => User::factory()->create()->id, 'visitor_hash' => null]);

    return $version;
}

it('shows the game chosen among those not archived, the address’s or else the first, and keeps the game and its filters in the address for this tab alone', function () {
    $first = $this->draft['game'];
    $first->forceFill(['title' => 'Trust Your Taste'])->save();
    $version = playedGame($this->draft);
    $swipe = Game::factory()->create(['title' => 'Swipe Challenge']);
    $archived = Game::factory()->status(GameStatus::Archived)->create(['title' => 'Old Quiz']);
    $choices = fn ($page): array => collect($page->viewData('data')['gameChoices'])->map(fn (array $choice): string => $choice['title'].($choice['chosen'] ? ' (chosen)' : ''))->all();

    $page = analyticsGames()
        ->assertSet('game', (string) $first->id)
        ->assertSee('data-testid="game-analytics-games"', false)
        ->assertSee('Rounds reached · V1');

    expect($choices($page))->toBe(['Trust Your Taste (chosen)', 'Swipe Challenge'])
        ->and($page->viewData('data')['gameTitle'])->toBe('Trust Your Taste');

    // Another game: its own analytics, a version of the first no version of its own.
    $page->set('version', (string) $version->id)->assertSet('version', (string) $version->id)
        ->set('game', (string) $swipe->id)
        ->assertSet('game', (string) $swipe->id)
        ->assertSet('version', '0')
        ->assertSee('Nothing to measure yet');
    expect($choices($page))->toBe(['Trust Your Taste', 'Swipe Challenge (chosen)']);

    // An archived game, one that isn't, or no game at all: the first.
    foreach ([$archived->id, 999999, 'swipe', ''] as $asked) {
        Livewire::withQueryParams(['tab' => 'games', 'game' => $asked])->test(GrowthAnalyticsPage::class)->assertSet('game', (string) $first->id);
    }

    // The game's own filters, read as the game's Analytics tab reads them.
    $paid = TrafficSource::factory()->create(['name' => 'Cat blog', 'type' => 'placement']);
    Livewire::withQueryParams(['tab' => 'games', 'game' => $first->id, 'group' => 'authenticated', 'version' => $version->id, 'country' => 'group:t1', 'by' => 'device', 'paid' => (string) $paid->id, 'sources_device' => 'mobile'])
        ->test(GrowthAnalyticsPage::class)
        ->assertSet('audience', 'authenticated')
        ->assertSet('version', (string) $version->id)
        ->assertSet('country', 'group:t1')
        ->assertSet('breakdown', 'device')
        ->assertSet('paidSource', (string) $paid->id)
        ->assertSet('sourcesDevice', 'mobile');

    // A paid source there is no such is every paid source.
    Livewire::withQueryParams(['tab' => 'games', 'game' => $first->id, 'paid' => (string) ($paid->id + 1000)])->test(GrowthAnalyticsPage::class)->assertSet('paidSource', '0');

    Livewire::withQueryParams(['tab' => 'games', 'group' => 'everyone', 'version' => GameVersion::factory()->create()->id, 'country' => 'ZZ', 'by' => 'city', 'paid' => 'all', 'sources_device' => 'tablet'])
        ->test(GrowthAnalyticsPage::class)
        ->assertSet('audience', 'anonymous')
        ->assertSet('version', '0')
        ->assertSet('country', '')
        ->assertSet('breakdown', 'version')
        ->assertSet('paidSource', '0')
        ->assertSet('sourcesDevice', 'all');

    // Another tab has no game: the game and its filters leave the address with the tab.
    Livewire::withQueryParams(['tab' => 'overview', 'game' => $first->id, 'group' => 'authenticated', 'by' => 'device'])->test(GrowthAnalyticsPage::class)
        ->assertSet('game', '')
        ->assertSet('audience', 'anonymous')
        ->assertSet('breakdown', 'version');

    analyticsGames(['group' => 'authenticated'])->set('tab', 'overview')->assertSet('game', '')->assertSet('audience', 'anonymous');
});

it('draws the very cards and figures of the game’s own Analytics for the same game, days and device', function () {
    playedGame($this->draft);
    $game = $this->draft['game'];
    $keys = ['audience', 'audiences', 'audienceCounts', 'versionOptions', 'country', 'countryOptions', 'visitNote', 'breakdown', 'breakdowns', 'breakdownNote', 'datesText', 'valueUrl', 'noVersions', 'noData', 'kpis', 'funnel', 'compared', 'focus', 'breakdownRows', 'signups', 'periodRows', 'periodVersion', 'periodsNote', 'trafficUrl', 'versionsUrl', 'paid', 'sources', 'sourcesSwitch', 'cardDevice', 'window', 'gameTitle'];

    // The page's period and device, beside what the game's own tab calls the same days.
    $cases = [
        'the default' => [[], []],
        'a preset and a device' => [['period' => '7d', 'device' => 'mobile'], ['period' => '7d', 'device' => 'mobile']],
        'the other device' => [['device' => 'desktop'], ['device' => 'desktop']],
        'a range of days' => [['from' => '2026-11-05', 'to' => '2026-11-18'], ['from' => '2026-11-05', 'to' => '2026-11-18']],
        'last month, as its days' => [['period' => 'last-month'], ['from' => '2026-10-01', 'to' => '2026-10-31']],
        'the game’s own filters' => [['group' => 'authenticated', 'by' => 'country'], ['group' => 'authenticated', 'by' => 'country']],
        'a country and the sources card’s device' => [['country' => 'DE', 'sources_device' => 'mobile', 'by' => 'device'], ['country' => 'DE', 'sources_device' => 'mobile', 'by' => 'device']],
    ];

    foreach ($cases as $case => [$analytics, $own]) {
        $tab = analyticsGames(['game' => $game->id, ...$analytics])->viewData('data');
        $page = Livewire::withQueryParams($own)->test(GameAnalytics::class, ['record' => $game->id]);

        foreach ($keys as $key) {
            // A preset the game's tab hasn't got is named as the page names it, where the game's tab names its days.
            if ($key !== 'window' || ($analytics['period'] ?? '') !== 'last-month') {
                expect($tab[$key])->toEqual($page->viewData($key), "{$case}: {$key}");
            }
        }
    }

    expect(analyticsGames(['period' => 'last-month'])->viewData('data')['window'])->toBe('last month');

    // The figures are there to compare: the shell's days and device do narrow them.
    $kpi = fn (array $data, string $label): string => collect($data['kpis'])->firstWhere('label', $label)['value'];
    $all = analyticsGames()->viewData('data');
    $mobile = analyticsGames(['device' => 'mobile'])->viewData('data');

    expect([$kpi($all, 'Visitors'), $kpi($all, 'Game started')])->toBe(['3', '2'])
        ->and([$kpi($mobile, 'Visitors'), $kpi($mobile, 'Game started')])->toBe(['1', '1'])
        ->and($mobile['visitNote'])->toBe('Only plays that came on a visit Traffic sources recorded are counted while a device or country is chosen.')
        // With a device chosen above, the sources card follows it and loses its own switch.
        ->and($mobile['sourcesSwitch'])->toBeFalse()
        ->and($all['datesText'])->toBe('20 Oct — 18 Nov 2026 · compared with 20 Sep — 19 Oct 2026')
        ->and(analyticsGames(['period' => 'yesterday'])->viewData('data')['datesText'])->toBe('17 Nov 2026 · compared with 16 Nov 2026')
        ->and(analyticsGames(['period' => 'yesterday'])->viewData('data')['noData'])->toBeTrue();

    // The way to Traffic sources carries the page's period as Traffic sources names it.
    expect(analyticsGames(['period' => '7d'])->viewData('data')['sources']['reportUrl'])->toContain('period=7d')
        ->and(analyticsGames(['period' => 'this-month'])->viewData('data')['sources']['reportUrl'])->toContain('from=2026-11-01')->toContain('to=2026-11-18');
});

it('says the source and campaign filters don’t apply while one is chosen, and nothing about recorded visits', function () {
    playedGame($this->draft);
    $notice = 'The source and campaign filters don’t apply on this tab.';

    // Nothing recorded by Analytics' own rules: a game's figures come from what playing recorded.
    $plain = analyticsGames()
        ->assertViewHas('state', 'ready')
        ->assertViewHas('notices', [])
        ->assertDontSee('Record visits is off, so Analytics has nothing new to count.')
        ->assertDontSee($notice);

    foreach ([['source' => 'reddit:organic'], ['campaign' => 'tag:spring'], ['source' => 'paid:1', 'campaign' => 'paid:2']] as $query) {
        $narrowed = analyticsGames($query)
            ->assertViewHas('notices', [['tone' => 'info', 'text' => $notice, 'action' => null]])
            ->assertSee($notice);

        expect($narrowed->viewData('data'))->toEqual($plain->viewData('data'));
    }

    // Another tab that reads visits keeps its own words, and says nothing of the kind.
    Livewire::withQueryParams(['source' => 'reddit:organic'])->test(GrowthAnalyticsPage::class)->call('load')
        ->assertSee('Record visits is off, so Analytics has nothing new to count.')
        ->assertDontSee($notice);
});

it('says so when there is no game yet, and when the game has no version yet', function () {
    $this->draft['game']->forceFill(['status' => GameStatus::Archived])->save();

    analyticsGames()
        ->assertSet('game', '')
        ->assertViewHas('data', fn (array $data): bool => $data['empty'] === true)
        ->assertSee('No games yet')
        ->assertSee('Open Games')
        ->assertDontSee('data-testid="game-analytics-filters"', false);

    $this->draft['game']->forceFill(['status' => GameStatus::Draft])->save();

    analyticsGames()
        ->assertSee('Nothing to measure yet')
        ->assertSee('Go to Versions')
        ->assertDontSee('No games yet');
});

it('reads no visit until the page is in a browser, and the tab in a bounded number of queries, however many games, versions, sources and countries', function () {
    playedGame($this->draft);
    $game = $this->draft['game'];

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->get(GrowthAnalyticsPage::getUrl(['tab' => 'games', 'game' => $game->id]))->assertOk()
        ->assertSee('wire:init="load"', false)
        ->assertDontSee('data-testid="game-analytics-kpis"', false);

    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $query): bool => str_contains($query, 'traffic_visits'))->values()->all())->toBe([]);
    DB::disableQueryLog();

    $queries = function (array $query): int {
        $page = Livewire::withQueryParams(['tab' => 'games', ...$query])->test(GrowthAnalyticsPage::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $page->call('load');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $few = $queries([]);

    // More games to choose from, more versions, more paid sources and countries the game's visitors came from.
    Game::factory()->count(6)->create();

    foreach (range(1, 4) as $ignored) {
        GameVersion::factory()->create(['game_id' => $game->id, 'number' => GameVersion::query()->where('game_id', $game->id)->max('number') + 1]);
    }

    foreach (['US', 'GB', 'ES', 'IT', 'PL'] as $index => $country) {
        TrafficVisit::factory()->create(['game_id' => $game->id, 'version_id' => GameVersion::query()->where('game_id', $game->id)->value('id'), 'source_id' => TrafficSource::factory()->create()->id, 'country' => $country, 'opened_at' => now(), 'visitor_hash' => hash('sha256', 'more-'.$index)]);
    }

    $many = $queries([]);
    $narrowed = $queries(['device' => 'mobile', 'country' => 'DE', 'by' => 'country', 'group' => 'authenticated']);

    // The shell's filters, the games, the game's versions, its figures now and before, the cards and the periods: never one per row.
    expect($few)->toBeLessThanOrEqual(48)
        ->and($many)->toBeLessThanOrEqual(48)
        ->and($narrowed)->toBeLessThanOrEqual(48)
        ->and($many - $few)->toBeLessThanOrEqual(2);
});

it('answers only an active administrator who may manage traffic sources, every request it is asked', function () {
    liveGame($this->draft);
    $page = analyticsGames();

    $this->actingAs(User::factory()->moderator()->create());
    $page->set('game', (string) $this->draft['game']->id)->assertForbidden();

    $this->get(GrowthAnalyticsPage::getUrl(['tab' => 'games']))->assertForbidden();
});
