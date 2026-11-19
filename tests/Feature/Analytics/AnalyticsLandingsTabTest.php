<?php

use App\Enums\GameAudience;
use App\Enums\GameStatus;
use App\Filament\Pages\Analytics\LandingsTab;
use App\Filament\Pages\GrowthAnalyticsPage;
use App\Models\Game;
use App\Models\GameVersion;
use App\Models\TrafficSource;
use App\Models\User;
use App\Support\Analytics\LandingRoute;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * Analytics → Landings: a row per page a session began on and the version it
 * showed, with all the session went on to do — a game started from the feed
 * counts on / — each row's path, the note of what its version had of the
 * game's traffic, its game, leading to that game on the Games tab, and its
 * flow; rates of visitors, of starters and of new visitors, "—" below the
 * denominator a row needs, and activation “Too early” while none of a row's
 * accounts has had its days to activate, the footnote saying which; every
 * filter of the page; then the open comparison started last, each version
 * against the reference, leading to its results on the Experiments tab, and
 * no card while none is open. Nobody matching is said once; the page reads
 * no visit until it is in a browser, and the tab a bounded number of queries.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00'));
    // The figures as they are at each read: the read cache has tests of its own (AnalyticsReadCacheTest).
    config(['analytics.read_cache_minutes' => 0]);
    $this->actingAs(User::factory()->admin()->create());
    $this->game = Game::factory()->status(GameStatus::Live)->create(['title' => 'Trust Your Taste', 'slug' => 'trust-your-taste']);
});

/** The open Landings tab's figures, read as the page reads them once it is in a browser. */
function analyticsLandings(array $query = []): array
{
    return Livewire::withQueryParams(['tab' => 'landings', ...$query])->test(GrowthAnalyticsPage::class)->call('load')->viewData('data');
}

/** The table's rows as text: where, the note, the game, the experience, the visitors and the four rates. */
function landingRows(array $data): array
{
    return array_map(fn (array $row): string => implode(' | ', [$row['path'], $row['note'] ?? '-', $row['game'], $row['experience'], $row['visitors'], ...array_column($row['rates'], 'value')]), $data['rows']);
}

/** A version of a game by its number, made when the game hasn't got it yet, playing a flow of the name given. */
function landingVersion(Game $game, int $number, string $flow): GameVersion
{
    $version = GameVersion::query()->where('game_id', $game->id)->where('number', $number)->first()
        ?? GameVersion::factory()->create(['game_id' => $game->id, 'number' => $number]);
    $version->flow->forceFill(['name' => $flow])->save();

    return $version;
}

/** The landing of a game's own page showing a version. */
function gameLanding(GameVersion $version): string
{
    return LandingRoute::key('games.play', (int) $version->game_id, (int) $version->id);
}

/**
 * Browsers that each began a session on a landing, with one visit there,
 * doing what the attributes given for their place say.
 *
 * @param  callable(int): array<string, mixed>|null  $did
 */
function landingVisitors(string $landing, int $count, ?callable $did = null, string $name = ''): void
{
    foreach (range(1, $count) as $n) {
        analyticsVisit(($name ?: $landing).'-'.$n, ['landing_key' => $landing, ...($did === null ? [] : $did($n))]);
    }
}

it('counts on the page a session began on all it went on to do: a game started from the feed counts on /', function () {
    $version = landingVersion($this->game, 7, 'Result First');

    foreach (range(1, 40) as $n) {
        $entry = analyticsVisit('feed-'.$n, ['landing_key' => 'feed', 'source_key' => 'reddit:organic']);

        // The session went on to the game: a visit of its own there, carrying the landing it began on.
        if ($n <= 10) {
            analyticsVisit('feed-'.$n, [
                'landing_key' => 'feed', 'source_key' => 'reddit:organic', 'entry_visit_id' => $entry->id,
                'game_id' => $this->game->id, 'version_id' => $version->id, 'arrived_at' => '2026-11-15 10:05:00',
                'started_at' => '2026-11-15 10:05:30', 'completed_at' => $n <= 8 ? '2026-11-15 10:07:00' : null,
            ]);
        }
    }

    // Someone who began on the game's own page.
    analyticsVisit('player-1', ['landing_key' => gameLanding($version), 'game_id' => $this->game->id, 'version_id' => $version->id, 'started_at' => '2026-11-15 10:01:00']);

    $data = analyticsLandings();

    expect(landingRows($data))->toBe([
        // Ten of forty started a game; their completions are a share of ten starters, too few to show.
        '/ | Starts count any game | No game | Home feed | 40 | 25.0% | — | 0.0% | 0.0%',
        '/games/trust-your-taste | - | Trust Your Taste | Result First · V7 | 1 | — | — | — | —',
    ])
        ->and($data['rows'][0]['rates'][0]['title'])->toBe('10 of 40')
        ->and($data['rows'][0]['gameUrl'])->toBeNull()
        ->and($data['description'])->toBe(LandingsTab::DESCRIPTION)
        ->and($data['description'])->toContain('Start rate uses visitors; completion uses starters; registration and activation use new visitors.');
});

it('gives each version shown its own row, a shared result its own, with what each version had of the traffic in the days', function () {
    $first = landingVersion($this->game, 7, 'Result First');
    $second = landingVersion($this->game, 8, 'Email Before Result');
    $exo = TrafficSource::factory()->create(['name' => 'ExoClick tests']);
    // The only version until 24 Oct, then 80/20; a split for ExoClick tests since 10 Nov.
    comparisonPeriod($this->game, [7 => 100], '2026-10-01 09:00:00', '2026-10-24 09:00:00');
    comparisonPeriod($this->game, [7 => 80, 8 => 20], '2026-10-24 09:00:00');
    comparisonPeriod($this->game, [7 => 50, 8 => 50], '2026-11-10 09:00:00')->forceFill(['source_id' => $exo->id])->save();
    // Logged-in players' periods are read only where a version had no anonymous visitors' share.
    comparisonPeriod($this->game, [7 => 50, 8 => 50], '2026-10-24 09:00:00', audience: GameAudience::Authenticated);

    landingVisitors(gameLanding($first), 3);
    landingVisitors(gameLanding($second), 2);
    landingVisitors(LandingRoute::key('games.result', (int) $this->game->id, (int) $second->id), 1);

    expect(landingRows(analyticsLandings()))->toBe([
        '/games/trust-your-taste | Everyone else: 80% · since 24 Oct; ExoClick tests: 50% · since 10 Nov | Trust Your Taste | Result First · V7 | 3 | — | — | — | —',
        '/games/trust-your-taste | Everyone else: 20% · since 24 Oct; ExoClick tests: 50% · since 10 Nov | Trust Your Taste | Email Before Result · V8 | 2 | — | — | — | —',
        '/games/trust-your-taste · shared result | Everyone else: 20% · since 24 Oct; ExoClick tests: 50% · since 10 Nov | Trust Your Taste | Email Before Result · V8 | 1 | — | — | — | —',
    ]);

    // Days of the first period alone: what the version had in them, not since.
    landingVisitors(gameLanding($first), 1, fn (): array => ['network_day' => '2026-10-21', 'arrived_at' => '2026-10-21 10:00:00'], 'october');

    expect(landingRows(analyticsLandings(['from' => '2026-10-20', 'to' => '2026-10-23'])))->toBe([
        // The version alone in a period now over: when it was, so a version alone after it reads apart.
        '/games/trust-your-taste | Only version · 01 Oct — 24 Oct | Trust Your Taste | Result First · V7 | 1 | — | — | — | —',
    ])
        // A period keeps the scope it reads as everywhere: everyone else's, beside the split started later.
        ->and(analyticsLandings(['from' => '2026-10-20', 'to' => '2026-10-30'])['rows'][0]['note'])->toBe('Everyone else: 80% · since 24 Oct');
});

it('notes a paused or archived game since when, a version logged-in players alone had, a period over by its days, and a split by its scope', function () {
    $paused = Game::factory()->status(GameStatus::Paused)->create(['title' => 'Swipe Challenge', 'slug' => 'swipe-challenge', 'status_changed_at' => '2026-10-01 08:00:00']);
    $archived = Game::factory()->status(GameStatus::Archived)->create(['title' => '60 Second Blitz', 'slug' => '60-second-blitz', 'status_changed_at' => '2026-11-12 08:00:00']);
    $players = Game::factory()->status(GameStatus::Live)->create(['title' => 'Rating Master', 'slug' => 'rating-master']);
    $blitz = Game::factory()->status(GameStatus::Live)->create(['title' => 'Hot or Not', 'slug' => 'hot-or-not']);
    $swipe = landingVersion($paused, 2, 'Result First');
    $old = landingVersion($archived, 1, 'Result First');
    $master = landingVersion($players, 1, 'Result First');
    $before = landingVersion($blitz, 1, 'Quick Result');
    $after = landingVersion($blitz, 2, 'Quick Result');
    comparisonPeriod($paused, [2 => 100], '2026-09-01 09:00:00');
    comparisonPeriod($players, [1 => 100], '2026-09-01 09:00:00', audience: GameAudience::Authenticated);
    comparisonPeriod($blitz, [1 => 60, 2 => 40], '2026-10-25 09:00:00', '2026-11-05 09:00:00');
    comparisonPeriod($blitz, [2 => 100], '2026-11-05 09:00:00');

    landingVisitors(gameLanding($swipe), 5);
    landingVisitors(gameLanding($old), 4);
    landingVisitors(gameLanding($master), 3);
    landingVisitors(gameLanding($before), 2);
    landingVisitors(gameLanding($after), 1);

    $data = analyticsLandings();

    expect(landingRows($data))->toBe([
        '/games/swipe-challenge | Paused 01 Oct | Swipe Challenge | Result First · V2 | 5 | — | — | — | —',
        '/games/60-second-blitz | Archived 12 Nov | 60 Second Blitz | Result First · V1 | 4 | — | — | — | —',
        '/games/rating-master | Logged-in players: only version | Rating Master | Result First · V1 | 3 | — | — | — | —',
        '/games/hot-or-not | 60% · 25 Oct — 05 Nov | Hot or Not | Quick Result · V1 | 2 | — | — | — | —',
        '/games/hot-or-not | Only version | Hot or Not | Quick Result · V2 | 1 | — | — | — | —',
    ])
        // An archived game is offered nowhere on the Games tab: its row leads nowhere. The period is named, the default too.
        ->and(array_column($data['rows'], 'gameUrl'))->toBe([
            GrowthAnalyticsPage::getUrl(['tab' => 'games', 'game' => $paused->id, 'period' => '30d']),
            null,
            GrowthAnalyticsPage::getUrl(['tab' => 'games', 'game' => $players->id, 'period' => '30d']),
            GrowthAnalyticsPage::getUrl(['tab' => 'games', 'game' => $blitz->id, 'period' => '30d']),
            GrowthAnalyticsPage::getUrl(['tab' => 'games', 'game' => $blitz->id, 'period' => '30d']),
        ]);
});

it('says activation is too early while none of a row’s accounts has had its days, and which rows the footnote means', function () {
    $version = landingVersion($this->game, 7, 'Result First');
    // Accounts made in the last days alone: none can be counted yet.
    landingVisitors(gameLanding($version), 40, fn (int $n): array => $n <= 4 ? ['signup_at' => '2026-11-14 10:00:00', 'account_at' => '2026-11-14 10:00:00'] : []);
    // Old enough, one of them activated.
    landingVisitors('feed', 40, fn (int $n): array => $n <= 4 ? ['signup_at' => '2026-11-02 10:00:00', 'account_at' => '2026-11-02 10:00:00', 'activated_at' => $n === 1 ? '2026-11-04 10:00:00' : null] : []);
    // Too few visitors to show a rate at all: an account too fresh is no reason given there.
    landingVisitors('posts.show', 10, fn (int $n): array => $n === 1 ? ['signup_at' => '2026-11-14 10:00:00', 'account_at' => '2026-11-14 10:00:00'] : []);

    $data = analyticsLandings();
    $rows = collect($data['rows'])->keyBy('experience');

    expect(array_column($rows['Result First · V7']['rates'], 'value'))->toBe(['0.0%', '—', '10.0%', 'Too early'])
        ->and($rows['Result First · V7']['tooEarly'])->toBeTrue()
        ->and($rows['Result First · V7']['rates'][3]['title'])->toBe('4 accounts, none old enough yet')
        ->and(array_column($rows['Home feed']['rates'], 'value'))->toBe(['0.0%', '—', '10.0%', '2.5%'])
        ->and(array_column($rows['Post']['rates'], 'value'))->toBe(['—', '—', '—', '—'])
        ->and($rows['Post']['tooEarly'])->toBeFalse()
        ->and($data['foot'])->toBe('Activation needs 7 days after signup. “Result First · V7” has no account that old yet, so its activation reads “Too early”. Accounts from 11 Nov on can still activate. '
            .'A visitor who began sessions on two landings counts on each. A rate shows “—” until the number it divides by reaches 30.');

    // Days all of whose accounts have had theirs say nothing more.
    landingVisitors('feed', 1, fn (): array => ['network_day' => '2026-11-01', 'arrived_at' => '2026-11-01 10:00:00'], 'early');

    expect(analyticsLandings(['from' => '2026-10-25', 'to' => '2026-11-05'])['foot'])
        ->toBe('Activation needs 7 days after signup. A visitor who began sessions on two landings counts on each. A rate shows “—” until the number it divides by reaches 30.');
});

it('reads every filter of the page, and says so once when nobody matches', function () {
    $version = landingVersion($this->game, 7, 'Result First');
    analyticsVisit('ann', ['landing_key' => 'feed', 'source_key' => 'reddit:organic', 'campaign_key' => 'tag:spring', 'device' => 'desktop']);
    analyticsVisit('bob', ['landing_key' => 'feed', 'source_key' => 'reddit:organic']);
    analyticsVisit('cat', ['landing_key' => gameLanding($version), 'source_key' => 'google:organic', 'game_id' => $this->game->id]);
    analyticsVisit('dan', ['landing_key' => 'posts.show', 'network_day' => '2026-10-01', 'arrived_at' => '2026-10-01 10:00:00']);

    $visitors = fn (array $query): array => array_map(fn (array $row): string => $row['path'].' '.$row['visitors'], analyticsLandings($query)['rows']);

    expect($visitors([]))->toBe(['/ 2', '/games/trust-your-taste 1'])
        ->and($visitors(['device' => 'desktop']))->toBe(['/ 1'])
        ->and($visitors(['source' => 'google:organic']))->toBe(['/games/trust-your-taste 1'])
        ->and($visitors(['source' => 'reddit:organic', 'campaign' => 'tag:spring']))->toBe(['/ 1'])
        ->and($visitors(['from' => '2026-09-25', 'to' => '2026-10-05']))->toBe(['/posts/… 1']);
    // The range is the period last chosen: forgotten, an address naming none opens on the last 30 days again.
    forgetChosenReportPeriods();

    $empty = Livewire::withQueryParams(['tab' => 'landings', 'source' => 'bing:organic', 'device' => 'mobile'])->test(GrowthAnalyticsPage::class)->call('load');

    expect($empty->viewData('data'))->toBe(['empty' => true, 'range' => '20 Oct — 18 Nov 2026', 'filtered' => true]);

    $empty->assertSee('No visitors for these filters')
        ->assertSee('Nothing matches in 20 Oct — 18 Nov 2026. Pick a longer range or clear the filters.')
        ->assertDontSee('Landing page performance')
        ->call('clearFilters')
        ->assertSet('source', '')
        ->assertSee('Landing page performance');
});

it('leads a game’s row to the game on the Games tab, of the same days and device, without the source and campaign', function () {
    $version = landingVersion($this->game, 7, 'Result First');
    analyticsVisit('ann', ['landing_key' => gameLanding($version), 'source_key' => 'reddit:organic', 'campaign_key' => 'tag:spring', 'device' => 'mobile', 'game_id' => $this->game->id]);

    $url = analyticsLandings(['period' => '7d', 'device' => 'mobile', 'source' => 'reddit:organic', 'campaign' => 'tag:spring'])['rows'][0]['gameUrl'];

    expect($url)->toBe(GrowthAnalyticsPage::getUrl(['tab' => 'games', 'game' => $this->game->id, 'period' => '7d', 'device' => 'mobile']))
        ->and(analyticsLandings(['from' => '2026-11-10', 'to' => '2026-11-16'])['rows'][0]['gameUrl'])
        ->toBe(GrowthAnalyticsPage::getUrl(['tab' => 'games', 'game' => $this->game->id, 'from' => '2026-11-10', 'to' => '2026-11-16']));

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    Livewire::withQueryParams($query)->test(GrowthAnalyticsPage::class)
        ->assertSet('tab', 'games')
        ->assertSet('game', (string) $this->game->id)
        ->assertSet('period', '7d')
        ->assertSet('device', 'mobile')
        ->assertSet('source', '')
        ->call('load')
        ->assertViewHas('data', fn (array $data): bool => collect($data['gameChoices'])->firstWhere('chosen', true)['id'] === $this->game->id);

    // The row says where it leads.
    Livewire::withQueryParams(['tab' => 'landings', 'period' => '7d'])->test(GrowthAnalyticsPage::class)->call('load')
        ->assertSeeHtml('href="'.e(GrowthAnalyticsPage::getUrl(['tab' => 'games', 'game' => $this->game->id, 'period' => '7d'])).'"');
});

it('sets the open comparison started last beside the landings, each version against the reference, leading to its results', function () {
    $first = landingVersion($this->game, 7, 'Result First');
    landingVersion($this->game, 8, 'Email Before Result');
    analyticsVisit('ann', ['landing_key' => gameLanding($first)]);
    // An ended comparison started later, and one version alone, are no open comparison.
    comparisonPeriod($this->game, [7 => 50, 8 => 50], '2026-11-12 09:00:00', '2026-11-13 09:00:00');
    comparisonPeriod($this->game, [7 => 100], '2026-11-14 09:00:00', audience: GameAudience::Authenticated);
    $period = comparisonPeriod($this->game, [7 => 80, 8 => 20], '2026-11-11 09:30:00');

    assignVisitors($period, 7, 10, fn (int $n): array => ['started' => $n <= 8, 'completed' => $n <= 6, ...($n <= 3 ? ['account' => '2026-11-12 10:00:00'] : [])]);
    assignVisitors($period, 8, 5, fn (int $n): array => ['started' => $n <= 4, 'completed' => $n <= 2, ...($n === 1 ? ['account' => '2026-11-12 10:00:00'] : [])]);

    $card = analyticsLandings()['comparison'];

    expect($card['id'])->toBe($period->id)
        ->and($card['title'])->toBe('Result First · V7 vs Email Before Result · V8')
        ->and($card['description'])->toBe('Trust Your Taste · Anonymous visitors. Same game, same days, a random split since 11 Nov. Rates use everyone assigned, not only those who finished; differences are relative to Result First · V7, the version with the largest share. Page filters don’t apply.')
        ->and($card['status'])->toBe(['label' => 'Current', 'tone' => 'success', 'dot' => true])
        ->and($card['heads'])->toBe(['reference' => 'Result First · V7', 'groups' => ['Email Before Result · V8']])
        ->and($card['fresh'])->toBeNull()
        ->and(array_map(fn (array $row): string => $row['label'].' | '.$row['reference']['value'].' | '.$row['groups'][0]['value'].' | '.$row['groups'][0]['change'], $card['rows']))->toBe([
            'Assigned visitors | 10 | 5 | ',
            'Started the game | 80.0% | 80.0% | +0.0%',
            'Completed the game | 60.0% | 40.0% | −33.3%',
            'Saw the full result | 0.0% | 0.0% | —',
            // Neither version's flow asks for an email.
            'Left an email | Not in this flow | Not in this flow | By design',
            'Created an account | 30.0% | 20.0% | −33.3%',
        ])
        ->and($card['rows'][5]['reference']['title'])->toBe('3 of 10')
        // Its results on the Experiments tab.
        ->and($card['url'])->toBe(GrowthAnalyticsPage::getUrl(['tab' => 'experiments', 'exp' => $period->id]));

    // The filters of the page don't apply to it.
    expect(analyticsLandings(['device' => 'mobile', 'source' => 'direct'])['comparison']['rows'])->toBe($card['rows']);

    // The button leads to the comparison's own results.
    parse_str((string) parse_url($card['url'], PHP_URL_QUERY), $query);

    expect(Livewire::withQueryParams($query)->test(GrowthAnalyticsPage::class)->assertSet('tab', 'experiments')->call('load')->viewData('data')['periodId'])->toBe($period->id);

    Livewire::withQueryParams(['tab' => 'landings'])->test(GrowthAnalyticsPage::class)->call('load')
        ->assertSee('Experiment results')
        ->assertSeeHtml('href="'.e($card['url']).'"');
});

it('compares three versions each against the reference, logged-in players by the goal, and says while nobody was sent yet', function () {
    analyticsVisit('ann', ['landing_key' => 'feed']);
    landingVersion($this->game, 7, 'Result First');
    landingVersion($this->game, 8, 'Email Before Result');
    landingVersion($this->game, 9, 'Direct Play');
    $three = comparisonPeriod($this->game, [7 => 30, 8 => 50, 9 => 20], '2026-11-11 09:30:00');

    foreach ([7 => 4, 8 => 4, 9 => 2] as $number => $count) {
        assignVisitors($three, $number, $count, fn (int $n): array => ['started' => $n <= 2]);
    }

    $card = analyticsLandings()['comparison'];

    expect($card['title'])->toBe('Email Before Result · V8 vs Result First · V7 vs Direct Play · V9')
        ->and($card['heads']['groups'])->toBe(['Result First · V7', 'Direct Play · V9'])
        ->and($card['columns'])->toContain('repeat(2, minmax(130px, 1fr) 110px)')
        ->and(array_map(fn (array $row): string => $row['label'].' | '.$row['reference']['value'].' | '.implode(' | ', array_map(fn (array $group): string => $group['value'].' '.$group['change'], $row['groups'])), array_slice($card['rows'], 0, 2)))->toBe([
            'Assigned visitors | 4 | 4  | 2 ',
            'Started the game | 50.0% | 50.0% +0.0% | 100.0% +100.0%',
        ]);

    // Logged-in players leave no email and make no account: they are compared by the goal.
    $players = comparisonPeriod($this->game, [7 => 50, 8 => 50], '2026-11-12 09:30:00', audience: GameAudience::Authenticated);
    assignVisitors($players, 7, 2, fn (int $n): array => ['player' => User::factory()->create(), 'goal' => $n === 1]);

    expect(array_column(analyticsLandings()['comparison']['rows'], 'label'))->toBe(['Assigned visitors', 'Started the game', 'Completed the game', 'Saw the full result', 'Reached the goal'])
        ->and(analyticsLandings()['comparison']['description'])->toStartWith('Trust Your Taste · Logged-in players. ');

    // A comparison nobody was sent to yet says so once, in place of the table.
    $fresh = comparisonPeriod($this->game, [8 => 50, 9 => 50], '2026-11-13 09:30:00');

    expect(analyticsLandings()['comparison'])->toMatchArray(['id' => $fresh->id, 'rows' => [], 'fresh' => 'This period just started. Numbers appear as visitors arrive.']);

    Livewire::withQueryParams(['tab' => 'landings'])->test(GrowthAnalyticsPage::class)->call('load')
        ->assertSee('No visitors yet')
        ->assertDontSee('Assigned visitors');
});

it('sets no comparison beside the landings while none is open', function () {
    analyticsVisit('ann', ['landing_key' => 'feed']);
    comparisonPeriod($this->game, [7 => 50, 8 => 50], '2026-11-01 09:00:00', '2026-11-10 09:00:00');
    comparisonPeriod($this->game, [7 => 100], '2026-11-10 09:00:00');
    comparisonPeriod(Game::factory()->status(GameStatus::Archived)->create(), [1 => 50, 2 => 50], '2026-11-10 09:00:00');

    expect(analyticsLandings()['comparison'])->toBeNull();

    Livewire::withQueryParams(['tab' => 'landings'])->test(GrowthAnalyticsPage::class)->call('load')
        ->assertSee('Landing page performance')
        ->assertDontSee('Experiment results');
});

it('reads no visit until the page is in a browser, and the tab in a bounded number of queries, the landings past the first rows together', function () {
    $queries = function (array $query): int {
        $page = Livewire::withQueryParams(['tab' => 'landings', ...$query])->test(GrowthAnalyticsPage::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $page->call('load');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $exo = TrafficSource::factory()->create(['name' => 'ExoClick tests']);
    // A game's landing with its periods, one of them a split, and visitors of the given number.
    $landing = function (int $n, int $visitors) use ($exo): void {
        $game = Game::factory()->status(GameStatus::Live)->create();
        $version = landingVersion($game, 1, 'Flow '.$n);
        comparisonPeriod($game, [1 => 100], '2026-10-01 09:00:00');
        comparisonPeriod($game, [1 => 50, 2 => 50], '2026-11-01 09:00:00')->forceFill(['source_id' => $exo->id])->save();
        landingVisitors(gameLanding($version), $visitors, name: 'landing-'.$n);
    };

    analyticsVisit('ann', ['landing_key' => 'feed']);
    $landing(0, 1);
    comparisonPeriod($this->game, [7 => 50, 8 => 50], '2026-11-11 09:30:00');
    $few = $queries([]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get(GrowthAnalyticsPage::getUrl(['tab' => 'landings']))->assertOk()->assertDontSee('Landing page performance');
    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $query): bool => str_contains($query, 'traffic_visits'))->values()->all())->toBe([]);
    DB::disableQueryLog();

    foreach (range(1, LandingsTab::ROWS + 1) as $n) {
        $landing($n, $n <= LandingsTab::ROWS ? 2 : 1);
    }

    // Visits from before landings were recorded.
    analyticsVisit('bob', ['landing_key' => null]);

    $data = analyticsLandings();
    $many = $queries([]);
    $narrowed = $queries(['source' => 'reddit:organic', 'device' => 'desktop', 'campaign' => 'tag:spring']);

    expect($data['rows'])->toHaveCount(LandingsTab::ROWS + 1)
        ->and(end($data['rows']))->toMatchArray(['path' => 'Other landing pages', 'note' => 'Every landing past the first 25, each visitor once', 'visitors' => '4', 'gameUrl' => null])
        // The table, its games, versions, periods and their scopes, then the comparison: never one per row.
        ->and($few)->toBeLessThanOrEqual(24)
        ->and($many)->toBeLessThanOrEqual(24)
        ->and($narrowed)->toBeLessThanOrEqual(24)
        // Everyone else's row is one more.
        ->and($many - $few)->toBeLessThanOrEqual(1);
});

it('says once that no row is old enough when every row is too early, and names each one in quotes otherwise', function () {
    $version = landingVersion($this->game, 7, 'Email, then a post');
    $fresh = fn (int $n): array => $n <= 4 ? ['signup_at' => '2026-11-14 10:00:00', 'account_at' => '2026-11-14 10:00:00'] : [];
    landingVisitors(gameLanding($version), 40, $fresh);
    landingVisitors('feed', 40, $fresh);

    expect(analyticsLandings()['foot'])->toContain('No row has an account that old yet, so activation reads “Too early”.')
        ->not->toContain('“Email, then a post · V7”');

    // One row old enough: the others are named, a flow's own comma kept inside its quotes, a page by its name.
    landingVisitors('posts.show', 40, fn (int $n): array => $n <= 4 ? ['signup_at' => '2026-11-02 10:00:00', 'account_at' => '2026-11-02 10:00:00'] : []);

    expect(analyticsLandings()['foot'])->toContain('“Email, then a post · V7”')->toContain('“Home feed”')->toContain('have no account that old yet')
        ->not->toContain('“Post”');
});
