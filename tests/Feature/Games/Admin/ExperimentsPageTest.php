<?php

use App\Actions\Games\HideGameTrafficPeriodAction;
use App\Enums\GameAudience;
use App\Enums\GameStatus;
use App\Exceptions\Games\CannotManageGameException;
use App\Filament\Pages\ExperimentsPage;
use App\Filament\Resources\Games\GameResource;
use App\Filament\Resources\Games\Pages\GameAnalytics;
use App\Filament\Resources\Games\Pages\GameTraffic;
use App\Models\Game;
use App\Models\GameTrafficPeriod;
use App\Models\GameVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

/**
 * Growth → Experiments: the traffic periods of every game not archived — the
 * latest started first, searched, narrowed to comparisons and to current or
 * ended ones, fifty a page — each with the data badge the game's own
 * Analytics gives it; a period's results, every version against the version
 * with the largest share; Delete period, which only hides an ended period,
 * with Undo; and the counter of comparisons running now. An active
 * administrator's who may manage games, and nobody else's.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00'));
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
    $this->game = Game::factory()->status(GameStatus::Live)->create(['title' => 'Trust Your Taste', 'internal_name' => 'Taste', 'slug' => 'trust-your-taste']);
});

function experimentsRows($page): array
{
    return collect($page->viewData('rows'))->map(fn (array $row): string => $row['game'].' | '.$row['audience'].' | '.$row['dates'].' | '.$row['mix'].' | '.$row['visitors'].' | '.$row['state']['label'].' | '.($row['current'] ? 'Current' : 'Ended'))->all();
}

function versionOf(GameTrafficPeriod $period, int $number): GameVersion
{
    return $period->versions->firstWhere('number', $number);
}

it('opens for an active administrator only, and answers nobody else, the page or any request it makes', function () {
    // Asking for the page reads no periods; they come once it is in a browser.
    $this->get(ExperimentsPage::getUrl())->assertOk()
        ->assertSee('Experiments')
        ->assertSee('wire:init="load"', false)
        ->assertDontSee('Every Apply in a game’s Traffic tab starts a period.');
    Livewire::test(ExperimentsPage::class)->assertViewHas('ready', false)->call('load')
        ->assertSee('Every Apply in a game’s Traffic tab starts a period. When a period has two or more versions, it compares them on the same metrics. Nothing to set up here.');

    $this->actingAs(User::factory()->moderator()->create())->get(ExperimentsPage::getUrl())->assertForbidden();
    $this->actingAs(User::factory()->admin()->limited()->create())->get(ExperimentsPage::getUrl())->assertForbidden();

    // Every request of a page already open asks again.
    $this->actingAs($this->admin);
    $page = Livewire::test(ExperimentsPage::class)->call('load');
    $this->actingAs(User::factory()->moderator()->create());

    $page->call('goToPage', 2)->assertForbidden();
});

it('lists the periods with shares of every game not archived, the latest started first, each with its badge', function () {
    $swipe = Game::factory()->status(GameStatus::Paused)->create(['title' => 'Swipe Challenge']);
    $single = comparisonPeriod($this->game, [7 => 100], '2026-09-12 09:30:00', '2026-09-24 09:30:00');
    $enough = comparisonPeriod($this->game, [7 => 50, 8 => 50], '2026-09-24 09:30:00', '2026-10-08 09:30:00');
    $early = comparisonPeriod($this->game, [7 => 80, 8 => 20], '2026-10-08 09:30:00');
    $empty = comparisonPeriod($swipe, [1 => 50, 2 => 50], '2026-11-17 10:00:00', audience: GameAudience::Authenticated);
    comparisonPeriod(Game::factory()->status(GameStatus::Archived)->create(), [1 => 100], '2026-10-01 10:00:00');

    foreach ([7, 8] as $number) {
        foreach (range(1, 10) as $ignored) {
            periodVisitor($enough, versionOf($enough, $number), ['account' => '2026-10-01 10:00:00']);
        }
    }

    periodVisitor($single, versionOf($single, 7));
    periodVisitor($early, versionOf($early, 7), ['account' => '2026-10-10 10:00:00']);

    $page = Livewire::test(ExperimentsPage::class)->call('load')->assertSee('4 periods')->assertSee('Shares change in each game’s Traffic tab. Periods can’t be edited here.');

    expect(experimentsRows($page))->toBe([
        'Swipe Challenge | Logged-in players | Since 17 Nov 2026 | V1 50% · V2 50% | 0 | No visitors yet | Current',
        'Trust Your Taste | Anonymous visitors | Since 08 Oct 2026 | V7 80% · V8 20% | 1 | Too early | Current',
        'Trust Your Taste | Anonymous visitors | 24 Sep — 08 Oct 2026 | V7 50% · V8 50% | 20 | Enough data | Ended',
        'Trust Your Taste | Anonymous visitors | 12 Sep — 24 Sep 2026 | V7 100% | 1 | One version | Ended',
    ])->and(collect($page->viewData('rows'))->pluck('days')->all())->toBe(['1 day', '41 days', '14 days', '12 days'])
        ->and($page->viewData('rows')[0]['url'])->toBe(ExperimentsPage::getUrl(['period' => $empty->id]));
});

it('filters by search, Show and Status in the address, and starts the list over', function () {
    $blitz = Game::factory()->status(GameStatus::Live)->create(['title' => '60 Second Blitz']);
    comparisonPeriod($this->game, [7 => 100], '2026-09-12 09:30:00', '2026-09-24 09:30:00');
    comparisonPeriod($this->game, [7 => 80, 8 => 20], '2026-10-08 09:30:00');
    comparisonPeriod($blitz, [2 => 100], '2026-09-10 09:30:00');

    $page = Livewire::withQueryParams(['page' => '3'])->test(ExperimentsPage::class)->call('load')
        ->set('show', 'comparisons')
        ->assertSet('pageNumber', 1)
        ->assertSee('1 period');
    expect(experimentsRows($page))->toHaveCount(1);

    $page->set('show', 'all')->set('status', 'ended');
    expect(experimentsRows($page))->toBe(['Trust Your Taste | Anonymous visitors | 12 Sep — 24 Sep 2026 | V7 100% | 0 | One version | Ended']);

    $page->set('status', 'all')->set('q', 'blitz');
    expect(collect($page->viewData('rows'))->pluck('game')->all())->toBe(['60 Second Blitz']);

    $page->set('q', 'nothing like it')->assertSee('No periods match these filters.')->assertSee('0 periods');

    // Anything the address says that the page can't read is the default.
    Livewire::withQueryParams(['show' => 'everything', 'status' => 'later', 'page' => 'x', 'period' => 'abc'])->test(ExperimentsPage::class)->call('load')
        ->assertSet('show', 'all')
        ->assertSet('status', 'all')
        ->assertSet('pageNumber', 1)
        ->assertSet('period', 0)
        ->assertViewHas('showsPeriod', false);
});

it('says when there are no periods at all', function () {
    Livewire::test(ExperimentsPage::class)->call('load')->assertSee('No traffic periods yet. Each Apply in a game’s Traffic tab starts one.');
});

it('reads fifty periods a page, every period’s figures in one query', function () {
    $version = comparisonPeriod($this->game, [7 => 100], '2026-01-01 00:00:00', '2026-01-02 00:00:00')->versions->first();

    foreach (range(1, 51) as $day) {
        GameTrafficPeriod::factory()->create(['game_id' => $this->game->id, 'started_at' => CarbonImmutable::parse('2026-02-01')->addDays($day), 'ended_at' => CarbonImmutable::parse('2026-02-01')->addDays($day + 1)])
            ->versions()->attach($version->id, ['share_percent' => 100]);
    }

    // The figures' query is periodFunnel()'s, named by its alias; the request's every query is counted too, so a read per row fails as well.
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $figures = function () use (&$queries): int {
        return count(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'AS assigned')));
    };

    $page = Livewire::test(ExperimentsPage::class);
    $queries = [];
    $page->call('load')->assertSee('52 periods')->assertSee('1–50 of 52 periods');
    [$firstFigures, $firstTotal] = [$figures(), count($queries)];
    expect($page->viewData('rows'))->toHaveCount(50);

    $queries = [];
    $page->call('goToPage', 2);
    expect($page->viewData('rows'))->toHaveCount(2)
        ->and([$firstFigures, $figures()])->toBe([1, 1])
        ->and(count($queries))->toBe($firstTotal);
});

it('shows a period’s versions against the one with the largest share, with the dashes and the footnote', function () {
    $period = comparisonPeriod($this->game, [7 => 50, 8 => 50], '2026-09-24 09:30:00', '2026-10-08 09:30:00');
    $v7 = versionOf($period, 7);
    $v8 = versionOf($period, 8);

    // V7: 20 visitors, 10 with an account, all of them old enough, 5 activated. V8: 20 visitors, 10 accounts, 2 activated.
    foreach (range(1, 20) as $n) {
        periodVisitor($period, $v7, $n <= 10 ? ['completed' => true, 'account' => '2026-10-01 10:00:00', 'activated' => $n <= 5] : ['started' => true]);
        periodVisitor($period, $v8, $n <= 10 ? ['completed' => true, 'goal' => $n <= 4, 'account' => '2026-10-01 10:00:00', 'activated' => $n <= 2] : []);
    }

    $page = Livewire::withQueryParams(['period' => (string) $period->id])->test(ExperimentsPage::class)->call('load')
        ->assertViewHas('showsPeriod', true)
        ->assertSee('Trust Your Taste · Anonymous visitors')
        ->assertSee('24 Sep — 08 Oct 2026 · 14 days · 40 visitors')
        ->assertSee('Enough data')
        ->assertSee('Enough accounts in every version.')
        ->assertSee('Rates use the visitors assigned to each version. Deltas compare with V7, the version with the largest share. The data badge follows accounts.')
        ->assertViewHas('heads', ['Game starts', 'Completions', 'Email captured', 'Registrations', 'Activated · 7 days', 'Goal reached']);

    $rows = collect($page->viewData('rows'))->map(fn (array $row): array => [$row['label'], $row['reference'], $row['share'], $row['visitors'], array_map(fn (array $cell): string => $cell['rate'].' / '.$cell['sub'].' / '.($cell['tone'] ?? '-'), $row['cells'])])->all();

    expect($rows)->toBe([
        ['V7', true, '50%', '20', ['100.0% / 20 / -', '50.0% / 10 / -', '— / Not in this flow / -', '50.0% / 10 / -', '25.0% / 5 / -', '0.0% / 0 / -']],
        ['V8', false, '50%', '20', ['50.0% / 10 · −50.0 pp / bad', '50.0% / 10 · +0.0 pp / -', '— / Not in this flow / -', '50.0% / 10 · +0.0 pp / -', '10.0% / 2 · −15.0 pp / bad', '20.0% / 4 · +20.0 pp / good']],
    ])->and($page->viewData('switcher'))->toHaveCount(1)
        ->and($page->viewData('trafficUrl'))->toBe(GameResource::getUrl('traffic', ['record' => $this->game]))
        ->and($page->viewData('versionsUrl'))->toBe(GameResource::getUrl('versions', ['record' => $this->game]))
        ->and($page->viewData('analyticsUrl'))->toBe(GameResource::getUrl('analytics', ['record' => $this->game, 'from' => '2026-09-24', 'to' => '2026-10-08']));
});

it('says it is too early, and which version is short, while a version has fewer than ten accounts; for logged-in players, by the goal', function () {
    $period = comparisonPeriod($this->game, [7 => 80, 8 => 20], '2026-11-15 09:30:00');
    periodVisitor($period, versionOf($period, 8), ['account' => '2026-11-16 10:00:00']);
    $players = comparisonPeriod($this->game, [7 => 50, 8 => 50], '2026-11-15 09:30:00', audience: GameAudience::Authenticated);
    periodVisitor($players, versionOf($players, 7), ['goal' => true, 'player' => User::factory()->create()]);

    Livewire::withQueryParams(['period' => (string) $period->id])->test(ExperimentsPage::class)->call('load')
        ->assertSee('Too early: V7 has 0 of 10 accounts, V8 has 1 of 10 accounts. Read the numbers as a trend for now.')
        ->assertSee('Since 15 Nov 2026 · 3 days · 1 visitor')
        // The only account hasn't had its seven days.
        ->assertSee('Needs 7 days')
        ->assertViewHas('canDelete', false);

    Livewire::withQueryParams(['period' => (string) $players->id])->test(ExperimentsPage::class)->call('load')
        ->assertSee('Too early: V7 has 1 of 10 players who reached the goal, V8 has 0 of 10 players who reached the goal.')
        ->assertSee('Already signed up')
        ->assertSee('No visitors yet')
        ->assertSee('The data badge follows players who reached the goal.')
        ->assertViewHas('analyticsUrl', GameResource::getUrl('analytics', ['record' => $this->game, 'from' => '2026-11-15', 'to' => '2026-11-18', 'group' => 'authenticated']));
});

it('says a period of one version has nothing to compare, and that a period gone isn’t found', function () {
    $period = comparisonPeriod($this->game, [7 => 100], '2026-09-12 09:30:00', '2026-09-24 09:30:00');

    Livewire::withQueryParams(['period' => (string) $period->id])->test(ExperimentsPage::class)->call('load')
        ->assertSee('Only V7 ran in this period, so there’s nothing to compare. Give a second version a share in Traffic to start a comparison.')
        ->assertSee('Rates use the visitors assigned to each version. The data badge follows accounts.')
        ->assertDontSee('Reference');

    Livewire::withQueryParams(['period' => '999999'])->test(ExperimentsPage::class)->call('load')
        ->assertSee('Period not found')
        ->assertSee('This period doesn’t exist any more.');
});

it('offers the other periods of the same game and audience, not hidden, the latest first', function () {
    $first = comparisonPeriod($this->game, [7 => 100], '2026-09-12 09:30:00', '2026-09-24 09:30:00');
    $second = comparisonPeriod($this->game, [7 => 50, 8 => 50], '2026-09-24 09:30:00', '2026-10-08 09:30:00');
    $third = comparisonPeriod($this->game, [7 => 80, 8 => 20], '2026-10-08 09:30:00');
    comparisonPeriod($this->game, [7 => 100], '2026-09-12 09:30:00', audience: GameAudience::Authenticated);
    $first->forceFill(['hidden_at' => now()])->save();

    $page = Livewire::withQueryParams(['period' => (string) $second->id])->test(ExperimentsPage::class)->call('load')
        ->assertSee('Periods for anonymous visitors');

    expect(collect($page->viewData('switcher'))->map(fn (array $each): string => $each['label'].' | '.$each['mix'].' | '.($each['current'] ? 'on' : 'off'))->all())->toBe([
        'Since 08 Oct | V7 80% · V8 20% | off',
        '24 Sep — 08 Oct | V7 50% · V8 50% | on',
    ])->and($page->viewData('switcher')[0]['url'])->toBe(ExperimentsPage::getUrl(['period' => $third->id]));
});

it('deletes an ended period by hiding it, back to the list with Undo, and logs who did', function () {
    Log::spy();
    $ended = comparisonPeriod($this->game, [7 => 50, 8 => 50], '2026-09-24 09:30:00', '2026-10-08 09:30:00');
    comparisonPeriod($this->game, [7 => 80, 8 => 20], '2026-10-08 09:30:00');

    Livewire::withQueryParams(['period' => (string) $ended->id])->test(ExperimentsPage::class)->call('load')
        ->assertViewHas('canDelete', true)
        ->call('deletePeriod')
        ->assertRedirect(ExperimentsPage::getUrl());

    expect($ended->fresh()->hidden_at?->toDateTimeString())->toBe('2026-11-18 12:00:00')
        ->and(session('rg-admin-toast'))->toBe(['message' => 'Period deleted. Its numbers stay in the database.', 'action' => ['label' => 'Undo', 'event' => 'show-experiment-period', 'params' => ['id' => $ended->id]]]);

    Log::shouldHaveReceived('info')->withArgs(fn (string $event, array $context): bool => $event === 'games.period.hidden' && $context['period_id'] === $ended->id && $context['admin_id'] === $this->admin->id)->once();

    // The list says so, with Undo; the period is out of it, and Undo brings it back.
    $list = Livewire::withQueryParams([])->test(ExperimentsPage::class)
        ->assertDispatched('rg-admin-toast', message: 'Period deleted. Its numbers stay in the database.', action: ['label' => 'Undo', 'event' => 'show-experiment-period', 'params' => ['id' => $ended->id]])
        ->call('load');
    expect(collect($list->viewData('rows'))->pluck('id')->all())->not->toContain($ended->id);

    $list->dispatch('show-experiment-period', id: $ended->id)->assertDispatched('rg-admin-toast', message: 'Period back in Experiments.');

    expect($ended->fresh()->hidden_at)->toBeNull()
        ->and(collect($list->viewData('rows'))->pluck('id')->all())->toContain($ended->id);
    Log::shouldHaveReceived('info')->withArgs(fn (string $event, array $context): bool => $event === 'games.period.shown' && $context['admin_id'] === $this->admin->id)->once();
});

it('opens a hidden period by its address, with its strip and the way back into the list', function () {
    $hidden = comparisonPeriod($this->game, [7 => 50, 8 => 50], '2026-09-24 09:30:00', '2026-10-08 09:30:00');
    $hidden->forceFill(['hidden_at' => now()])->save();

    $page = Livewire::withQueryParams(['period' => (string) $hidden->id])->test(ExperimentsPage::class)->call('load')
        ->assertSee('Hidden from Experiments.')
        ->assertSee('Show in Experiments')
        ->assertViewHas('canDelete', false)
        // Not in its own switcher, nor in the list.
        ->assertViewHas('switcher', []);
    expect(collect(Livewire::withQueryParams([])->test(ExperimentsPage::class)->call('load')->viewData('rows'))->pluck('id')->all())->toBe([]);

    // Back in the list, the page reloads its address, so the top bar offers Delete period again.
    $page->call('showPeriod')->assertRedirect(ExperimentsPage::getUrl(['period' => $hidden->id]));
    expect($hidden->fresh()->hidden_at)->toBeNull()
        ->and(session('rg-admin-toast'))->toBe(['message' => 'Period back in Experiments.']);
    $this->get(ExperimentsPage::getUrl(['period' => $hidden->id]))->assertSee('Delete period');
});

it('puts Delete period and Open Traffic in the top bar of a period, Delete only while the period can be deleted', function () {
    $ended = comparisonPeriod($this->game, [7 => 50, 8 => 50], '2026-09-24 09:30:00', '2026-10-08 09:30:00');
    $open = comparisonPeriod($this->game, [7 => 80, 8 => 20], '2026-10-08 09:30:00');
    $traffic = GameResource::getUrl('traffic', ['record' => $this->game]);

    $this->get(ExperimentsPage::getUrl(['period' => $ended->id]))->assertOk()
        ->assertSee('Delete period')
        ->assertSee("\$dispatch('delete-experiment-period')", false)
        ->assertSee('Open Traffic')
        ->assertSee($traffic, false);
    $this->get(ExperimentsPage::getUrl(['period' => $open->id]))->assertOk()->assertDontSee('Delete period')->assertSee('Open Traffic');
    // The list, and a period the page wouldn't find, have neither.
    $this->get(ExperimentsPage::getUrl())->assertOk()->assertDontSee('Open Traffic');
    $this->get(ExperimentsPage::getUrl(['period' => 999999]))->assertOk()->assertDontSee('Open Traffic');

    // The top bar's button reaches the page by its event.
    Livewire::withQueryParams(['period' => (string) $ended->id])->test(ExperimentsPage::class)->call('load')
        ->dispatch('delete-experiment-period')
        ->assertRedirect(ExperimentsPage::getUrl());
    expect($ended->fresh()->hidden_at)->not->toBeNull();
});

it('hides only an ended period, and changes nothing asked twice', function () {
    Log::spy();
    $open = comparisonPeriod($this->game, [7 => 100], '2026-10-08 09:30:00');
    $ended = comparisonPeriod($this->game, [7 => 100], '2026-09-24 09:30:00', '2026-10-08 09:30:00');
    $hide = app(HideGameTrafficPeriodAction::class);

    expect(fn () => $hide->hide($this->admin, $open))->toThrow(CannotManageGameException::class, 'Only an ended period can be deleted.')
        ->and($open->fresh()->hidden_at)->toBeNull()
        ->and($hide->hide($this->admin, $ended))->toBeTrue()
        ->and($hide->hide($this->admin, $ended))->toBeFalse()
        ->and($hide->show($this->admin, $ended))->toBeTrue()
        ->and($hide->show($this->admin, $ended))->toBeFalse();

    Log::shouldHaveReceived('info')->withArgs(fn (string $event): bool => $event === 'games.period.hidden')->once();
    Log::shouldHaveReceived('info')->withArgs(fn (string $event): bool => $event === 'games.period.shown')->once();

    // The page offers no Delete for an open period, and refuses one asked for anyway.
    Livewire::withQueryParams(['period' => (string) $open->id])->test(ExperimentsPage::class)->call('load')
        ->assertDontSee('Delete period')
        ->call('deletePeriod')
        ->assertDispatched('rg-admin-toast', tone: 'error')
        ->assertNoRedirect();
});

it('counts the comparisons running now beside Experiments in the navigation', function () {
    comparisonPeriod($this->game, [7 => 80, 8 => 20], '2026-10-08 09:30:00');
    comparisonPeriod($this->game, [7 => 50, 8 => 50], '2026-10-08 09:30:00', audience: GameAudience::Authenticated);
    comparisonPeriod($this->game, [7 => 50, 8 => 50], '2026-09-24 09:30:00', '2026-10-08 09:30:00');

    expect(ExperimentsPage::getNavigationBadge())->toBe('2');

    GameTrafficPeriod::query()->update(['ended_at' => now()]);

    expect(ExperimentsPage::getNavigationBadge())->toBeNull();
});

it('gives a period the same badge in the game’s Analytics as in Experiments', function () {
    $draft = publishableGameDraft(rounds: 1, admin: $this->admin);
    $v1 = liveGame($draft);
    $game = $draft['game']->fresh();
    $v2 = GameVersion::factory()->create(['game_id' => $game->id, 'number' => 2]);
    $periods = [
        'single' => GameTrafficPeriod::query()->where('game_id', $game->id)->where('audience', GameAudience::Anonymous->value)->sole(),
        'empty' => GameTrafficPeriod::factory()->create(['game_id' => $game->id, 'started_at' => '2026-11-10 10:00:00', 'ended_at' => '2026-11-11 10:00:00']),
        'early' => GameTrafficPeriod::factory()->create(['game_id' => $game->id, 'started_at' => '2026-11-11 10:00:00', 'ended_at' => '2026-11-12 10:00:00']),
        'enough' => GameTrafficPeriod::factory()->create(['game_id' => $game->id, 'started_at' => '2026-11-12 10:00:00', 'ended_at' => '2026-11-13 10:00:00']),
    ];

    foreach (['empty', 'early', 'enough'] as $state) {
        $periods[$state]->versions()->attach([$v1->id => ['share_percent' => 50], $v2->id => ['share_percent' => 50]]);
    }

    foreach ([$v1, $v2] as $version) {
        foreach (range(1, 10) as $n) {
            periodVisitor($periods['enough'], $version, ['account' => '2026-11-12 11:00:00']);
            periodVisitor($periods['early'], $version, $n <= 9 || $version->is($v1) ? ['account' => '2026-11-11 11:00:00'] : ['started' => true]);
        }
    }

    $experiments = collect(Livewire::test(ExperimentsPage::class)->call('load')->viewData('rows'))->mapWithKeys(fn (array $row): array => [$row['id'] => $row['state']['label']]);
    $analytics = collect(Livewire::test(GameAnalytics::class, ['record' => $game->id])->viewData('periodRows'))
        ->mapWithKeys(fn (array $row): array => [(int) str($row['resultsUrl'])->afterLast('period=')->toString() => $row['badge']['label']]);

    expect($experiments->only(collect($periods)->pluck('id'))->sortKeys()->all())->toBe($analytics->sortKeys()->all())
        ->and($experiments[$periods['single']->id])->toBe('One version')
        ->and($experiments[$periods['empty']->id])->toBe('No visitors yet')
        ->and($experiments[$periods['early']->id])->toBe('Too early')
        ->and($experiments[$periods['enough']->id])->toBe('Enough data');
});

it('links each period with shares on the game’s Traffic and Analytics tabs to its results', function () {
    $draft = publishableGameDraft(rounds: 1, admin: $this->admin);
    liveGame($draft);
    $game = $draft['game']->fresh();
    $period = GameTrafficPeriod::query()->where('game_id', $game->id)->where('audience', GameAudience::Anonymous->value)->sole();
    $url = ExperimentsPage::getUrl(['period' => $period->id]);
    // A period that gave its visitors to no version has no results.
    GameTrafficPeriod::factory()->create(['game_id' => $game->id, 'audience' => GameAudience::Authenticated, 'started_at' => '2026-11-01 10:00:00', 'ended_at' => '2026-11-02 10:00:00']);

    Livewire::test(GameTraffic::class, ['record' => $game->id])
        ->assertSeeHtml('href="'.e($url).'"')
        ->assertViewHas('periods', fn (array $periods): bool => collect($periods)->whereNull('resultsUrl')->count() === 1 && collect($periods)->pluck('resultsUrl')->filter()->count() === 2);

    Livewire::test(GameAnalytics::class, ['record' => $game->id])
        ->assertSeeHtml('href="'.e($url).'"');
});
