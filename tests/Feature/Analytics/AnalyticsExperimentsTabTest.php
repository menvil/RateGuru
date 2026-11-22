<?php

use App\Enums\GameAudience;
use App\Enums\GameStatus;
use App\Filament\Pages\ExperimentsPage;
use App\Filament\Pages\GrowthAnalyticsPage;
use App\Models\Game;
use App\Models\GameContentSet;
use App\Models\GameFlow;
use App\Models\GameVersion;
use App\Models\User;
use App\Support\Analytics\ComparisonVerdict;
use Carbon\CarbonImmutable;
use Database\Factories\GameVersionFactory;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * Analytics → Experiments: the latest comparisons — newest first, twelve at
 * most, the address's own even when older — and the one chosen, its every
 * metric against the reference version, read from the visitors each version
 * was assigned as Growth → Experiments reads them, so both say the same; two,
 * three and four versions; logged-in players compared by the goal, their
 * accounts not comparable by design; activation only of accounts old enough;
 * a paused game's period; the split checked against the shares; what it says
 * with no comparison and with nobody sent yet; no filter of the page, no word
 * about recorded visits, and a bounded number of queries.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00'));
    // The figures as they are at each read: the read cache has tests of its own (AnalyticsReadCacheTest).
    config(['analytics.read_cache_minutes' => 0]);
    $this->actingAs(User::factory()->admin()->create());
    $this->game = Game::factory()->status(GameStatus::Live)->create(['title' => 'Trust Your Taste']);
});

/** The open Experiments tab's figures, read as the page reads them once it is in a browser. */
function analyticsExperiments(array $query = []): array
{
    return Livewire::withQueryParams(['tab' => 'experiments', ...$query])->test(GrowthAnalyticsPage::class)->call('load')->viewData('data');
}

/** The table's rows as text: the metric, then the reference's figure, then each other version's figure, change and evidence. */
function experimentRows(array $data): array
{
    return array_map(fn (array $row): string => $row['label'].($row['primary'] ? ' [Primary]' : '')
        .' | '.trim($row['reference']['rate'].' '.$row['reference']['count'])
        .implode('', array_map(fn (array $group): string => ' | '.trim($group['rate'].' '.$group['count']).' | '.$group['change'].' | '.($group['evidence']['label'] ?? '-'), $row['groups'])), $data['table']['rows']);
}

/** A version of the game whose flow asks anonymous visitors for an email before the full result. */
function emailAskingVersion(Game $game, int $number): GameVersion
{
    $flow = GameFlow::factory()->create(['game_id' => $game->id, 'name' => 'Email Before Result']);
    $snapshot = GameVersionFactory::snapshotFor($game->id, $flow->id);
    array_splice($snapshot['flow']['steps'], 2, 0, [
        ['key' => 's2a', 'type' => 'result_preview', 'audience' => 'all', 'settings' => [], 'texts' => []],
        ['key' => 's2b', 'type' => 'email_capture', 'audience' => 'anonymous', 'settings' => [], 'texts' => []],
    ]);

    return GameVersion::factory()->create([
        'game_id' => $game->id, 'number' => $number, 'flow_id' => $flow->id, 'snapshot' => $snapshot,
        'content_set_id' => GameContentSet::factory()->create(['game_id' => $game->id, 'name' => 'Spring set'])->id,
    ]);
}

it('offers the latest comparisons, twelve at most, with the way to all of them, and shows the one the address names', function () {
    $swipe = Game::factory()->status(GameStatus::Paused)->create(['title' => 'Swipe Challenge']);
    $periods = [];

    foreach (range(1, 13) as $n) {
        $periods[$n] = comparisonPeriod($this->game, [7 => 50, 8 => 50], CarbonImmutable::parse('2026-08-01 09:30:00')->addDays($n * 3)->toDateTimeString(), CarbonImmutable::parse('2026-08-01 09:30:00')->addDays($n * 3 + 3)->toDateTimeString());
    }

    $current = comparisonPeriod($this->game, [7 => 80, 8 => 20], '2026-11-11 09:30:00');
    $paused = comparisonPeriod($swipe, [1 => 50, 2 => 50], '2026-11-01 08:00:00', audience: GameAudience::Authenticated);
    // Not comparisons Experiments lists: one version, hidden, of an archived game.
    comparisonPeriod($this->game, [7 => 100], '2026-11-12 09:30:00', audience: GameAudience::Authenticated);
    comparisonPeriod($this->game, [7 => 50, 8 => 50], '2026-11-13 09:30:00', '2026-11-14 09:30:00')->forceFill(['hidden_at' => now()])->save();
    comparisonPeriod(Game::factory()->status(GameStatus::Archived)->create(), [1 => 50, 2 => 50], '2026-11-14 09:30:00');

    $data = analyticsExperiments();

    expect($data['caption'])->toBe('Uses the population assigned to each version. Page filters don’t apply.')
        ->and($data['allUrl'])->toBe(ExperimentsPage::getUrl(['show' => 'comparisons']))
        ->and(array_map(fn (array $choice): string => $choice['name'].' | '.$choice['audience'].' | '.$choice['meta'].($choice['chosen'] ? ' | chosen' : ''), array_slice($data['choices'], 0, 4)))->toBe([
            // The audience on the chip itself: one game's periods otherwise read alike.
            'Trust Your Taste · Anonymous visitors | Anonymous visitors | Current · day 8 | chosen',
            'Swipe Challenge · Logged-in players | Logged-in players | Paused · day 18',
            'Trust Your Taste · Anonymous visitors | Anonymous visitors | Ended 12 Sep',
            'Trust Your Taste · Anonymous visitors | Anonymous visitors | Ended 09 Sep',
        ])
        ->and(array_column($data['choices'], 'id'))->toHaveCount(12)->not->toContain($periods[1]->id, $periods[2]->id)
        // With none named, the latest started.
        ->and($data['periodId'])->toBe($current->id);

    expect(analyticsExperiments(['exp' => (string) $paused->id])['periodId'])->toBe($paused->id);

    // An older one the address names is shown, and offered after the latest.
    $older = analyticsExperiments(['exp' => (string) $periods[1]->id]);
    expect($older['periodId'])->toBe($periods[1]->id)
        ->and(array_column($older['choices'], 'id'))->toHaveCount(13)
        ->and(end($older['choices']))->toMatchArray(['id' => $periods[1]->id, 'meta' => 'Ended 07 Aug', 'chosen' => true]);

    // A chip shows its comparison and puts it in the address.
    Livewire::withQueryParams(['tab' => 'experiments'])->test(GrowthAnalyticsPage::class)->call('load')
        ->set('exp', (string) $paused->id)
        ->assertSet('exp', (string) $paused->id)
        ->assertViewHas('data', fn (array $data): bool => $data['periodId'] === $paused->id);
});

it('keeps the comparison in the address for its tab alone, and reads one it can’t show as the latest', function () {
    $listed = comparisonPeriod($this->game, [7 => 50, 8 => 50], '2026-11-01 09:30:00');
    $single = comparisonPeriod($this->game, [7 => 100], '2026-11-02 09:30:00', audience: GameAudience::Authenticated);
    $hidden = comparisonPeriod($this->game, [7 => 50, 8 => 50], '2026-10-01 09:30:00', '2026-10-05 09:30:00');
    $hidden->forceFill(['hidden_at' => now()])->save();

    foreach (['abc', '-3', '0', '1.5', (string) $single->id, (string) $hidden->id, '999999'] as $exp) {
        $page = Livewire::withQueryParams(['tab' => 'experiments', 'exp' => $exp])->test(GrowthAnalyticsPage::class)->call('load')->assertSet('exp', '');

        expect($page->viewData('data')['periodId'])->toBe($listed->id);
    }

    Livewire::withQueryParams(['tab' => 'overview', 'exp' => (string) $listed->id])->test(GrowthAnalyticsPage::class)->assertSet('exp', '');
    Livewire::withQueryParams(['tab' => 'experiments', 'exp' => (string) $listed->id])->test(GrowthAnalyticsPage::class)->call('load')
        ->assertSet('exp', (string) $listed->id)
        ->set('tab', 'overview')
        ->assertSet('exp', '');
});

it('compares two versions: the header, the versions’ panels, the verdict, every metric against the reference with the Primary row, and the conditional rates', function () {
    $period = comparisonPeriod($this->game, [7 => 50, 8 => 50], '2026-10-08 09:30:00', '2026-10-22 09:30:00');

    // V7: 30 visitors, every one started, 25 completed and saw the result with an account, 10 of them activated.
    assignVisitors($period, 7, 30, fn (int $n): array => $n <= 25 ? ['result' => true, 'account' => '2026-10-10 10:00:00', 'activated' => $n <= 10] : ['started' => true]);
    // V8: 30 visitors, 20 completed, 10 saw the result with an account, 2 activated; 10 never started.
    assignVisitors($period, 8, 30, fn (int $n): array => match (true) {
        $n <= 10 => ['result' => true, 'account' => '2026-10-10 10:00:00', 'activated' => $n <= 2],
        $n <= 20 => ['completed' => true],
        default => [],
    });

    $data = analyticsExperiments();

    expect($data)->toMatchArray([
        'title' => 'V7 vs V8',
        'subtitle' => 'Trust Your Taste · Anonymous visitors',
        'status' => ['label' => 'Ended', 'tone' => 'neutral', 'dot' => false],
        'facts' => [
            ['label' => 'Duration', 'value' => '08 Oct — 22 Oct'],
            ['label' => 'Traffic', 'value' => 'V7 50% · V8 50%'],
            ['label' => 'Primary metric', 'value' => 'Created an account'],
            ['label' => 'Assigned', 'value' => '60'],
        ],
        'fresh' => null,
    ])->and(array_map(fn (array $variant): string => $variant['key'].' | '.$variant['meta'].($variant['reference'] ? ' | Reference' : ''), $data['variants']))->toBe([
        'V7 | 50% share · 30 assigned | Reference',
        'V8 | 50% share · 30 assigned',
    ])->and(array_column($data['variants'][0]['steps'], 'label'))->toBe(['Landing', 'Game', 'Full Result'])
        ->and(array_column($data['variants'][0]['steps'], 'kind'))->toBe(['step', 'game', 'step'])
        // Every other version is behind the reference with strong evidence: the reference won.
        ->and($data['verdict'])->toBe(['tone' => 'success', 'icon' => 'circle-check', 'kind' => 'won', 'title' => 'V7 won on accounts.', 'text' => 'V8 −60.0% against V7, with strong evidence.'])
        ->and($data['table']['heads'])->toBe(['reference' => 'V7 · Reference', 'groups' => [['version' => 'V8', 'change' => 'V8 vs V7']]])
        ->and(experimentRows($data))->toBe([
            'Assigned visitors | 30 | 30 | — | Balanced',
            'Started the game | 100.0% 30 of 30 | 66.7% 20 of 30 | −33.3% | Strong evidence',
            'Completed the game | 83.3% 25 of 30 | 66.7% 20 of 30 | −20.0% | Behind, not sure yet',
            'Saw the full result | 83.3% 25 of 30 | 33.3% 10 of 30 | −60.0% | Strong evidence',
            'Left an email | — Not in this flow | — Not in this flow | — | By design',
            'Created an account [Primary] | 83.3% 25 of 30 | 33.3% 10 of 30 | −60.0% | Strong evidence',
            'Activated within 7 days | 33.3% 10 of 30 | 6.7% 2 of 30 | −80.0% | Too early',
            'Reached the goal | 0.0% 0 of 30 | 0.0% 0 of 30 | — | Too early',
        ])
        ->and(array_column($data['table']['rows'], 'sub'))->toBe(['Split 50/50 · 50.0% · 50.0%', '', '', '', '', 'Confirmed new accounts', 'Mature accounts: 25 · 10 (signed up 8+ days ago)', ''])
        ->and(array_map(fn (array $row): string => $row['label'].' | '.$row['reference'].' | '.$row['groups'][0]['rate'].' | '.$row['groups'][0]['change'], $data['table']['conditional']))->toBe([
            'Accounts among completers | 100.0% | 50.0% | −50.0%',
            'Emails among completers | — | — | —',
        ])
        ->and($data['table']['foot'])->toBe('Rates use every visitor assigned to each version; changes are relative to V7, the version with the largest share. Evidence comes from a two-proportion test: “Strong evidence” from 95% confidence, “Ahead, not sure yet” or “Behind, not sure yet” from 80%, “Too early” while either side has fewer than 10 conversions on that row. Activation counts only accounts that signed up 8 or more days ago. The verdict follows accounts.');

    Livewire::withQueryParams(['tab' => 'experiments'])->test(GrowthAnalyticsPage::class)->call('load')
        ->assertSee('Uses the population assigned to each version. Page filters don’t apply.')
        ->assertSee('All in Experiments')
        ->assertSee('Metric · share of assigned')
        ->assertSee('Conditional rates · secondary')
        ->assertSee('Primary');
});

it('shows the same figures as Growth → Experiments for the same period', function () {
    $period = comparisonPeriod($this->game, [7 => 80, 8 => 20], '2026-10-08 09:30:00');
    assignVisitors($period, 7, 12, fn (int $n): array => $n <= 7 ? ['completed' => true, 'goal' => $n <= 3, 'account' => '2026-10-10 10:00:00', 'activated' => $n <= 2] : ['started' => $n <= 10]);
    assignVisitors($period, 8, 9, fn (int $n): array => $n <= 4 ? ['result' => true, 'account' => '2026-11-15 10:00:00', 'activated' => true] : []);

    $tab = collect(analyticsExperiments()['table']['rows'])->keyBy('label');
    $experiments = Livewire::withQueryParams(['period' => (string) $period->id])->test(ExperimentsPage::class)->call('load');
    $columns = ['Started the game', 'Completed the game', 'Left an email', 'Created an account', 'Activated within 7 days', 'Reached the goal'];

    foreach ($experiments->viewData('rows') as $row) {
        $figures = array_map(function (string $label) use ($tab, $row): string {
            $cell = $row['reference'] ? $tab[$label]['reference'] : $tab[$label]['groups'][0];

            return $cell['rate'];
        }, $columns);

        expect($figures)->toBe(array_column($row['cells'], 'rate'), $row['label'].' reads differently');
    }
});

it('compares three and four versions, each against the reference', function (array $shares, string $reference, array $groups, string $split) {
    $period = comparisonPeriod($this->game, $shares, '2026-11-10 09:30:00');

    foreach (array_keys($shares) as $number) {
        assignVisitors($period, $number, 3, fn (int $n): array => ['started' => $n <= 2]);
    }

    $data = analyticsExperiments();

    expect($data['title'])->toBe(implode(' vs ', array_map(fn (int $number): string => 'V'.$number, array_keys($shares))))
        ->and($data['table']['heads']['reference'])->toBe($reference)
        // The split reads in the columns' order, the reference first.
        ->and($data['table']['rows'][0]['sub'])->toBe($split)
        ->and(array_column($data['table']['heads']['groups'], 'change'))->toBe($groups)
        ->and($data['table']['columns'])->toContain('repeat('.count($groups).', minmax(112px, 1fr) 88px 160px)')
        ->and(collect($data['table']['rows'])->every(fn (array $row): bool => count($row['groups']) === count($groups)))->toBeTrue()
        ->and(collect($data['table']['conditional'])->every(fn (array $row): bool => count($row['groups']) === count($groups)))->toBeTrue()
        // The split is the period's as a whole: said once.
        ->and(array_map(fn (array $group): ?string => $group['evidence']['label'] ?? null, $data['table']['rows'][0]['groups']))->toBe(['Too early', ...array_fill(0, count($groups) - 1, null)])
        ->and($data['verdict']['kind'])->toBe(ComparisonVerdict::RUNNING)
        ->and($data['verdict']['text'])->toStartWith('Day 9. Too early: ');
})->with([
    'three versions, the largest share last' => [[1 => 20, 2 => 30, 3 => 50], 'V3 · Reference', ['V1 vs V3', 'V2 vs V3'], 'Split 50/20/30 · 33.3% · 33.3% · 33.3%'],
    'four versions, the lowest number among equal shares' => [[1 => 25, 2 => 25, 3 => 25, 4 => 25], 'V1 · Reference', ['V2 vs V1', 'V3 vs V1', 'V4 vs V1'], 'Split 25/25/25/25 · 25.0% · 25.0% · 25.0% · 25.0%'],
]);

it('compares logged-in players by the goal, their accounts and activation not comparable by design', function () {
    $period = comparisonPeriod($this->game, [7 => 50, 8 => 50], '2026-11-01 09:30:00', audience: GameAudience::Authenticated);
    assignVisitors($period, 7, 12, fn (int $n): array => ['completed' => true, 'goal' => $n <= 11, 'player' => User::factory()->create()]);
    assignVisitors($period, 8, 12, fn (int $n): array => ['completed' => true, 'goal' => $n <= 10, 'player' => User::factory()->create()]);

    $data = analyticsExperiments();
    $rows = collect(experimentRows($data));

    expect($data['subtitle'])->toBe('Trust Your Taste · Logged-in players')
        ->and($data['facts'][2])->toBe(['label' => 'Primary metric', 'value' => 'Reached the goal'])
        ->and($rows->first(fn (string $row): bool => str_starts_with($row, 'Created an account')))->toBe('Created an account | — Already signed up | — Already signed up | — | By design')
        ->and($rows->first(fn (string $row): bool => str_starts_with($row, 'Activated within 7 days')))->toBe('Activated within 7 days | — Already signed up | — Already signed up | — | By design')
        ->and($rows->first(fn (string $row): bool => str_starts_with($row, 'Left an email')))->toBe('Left an email | — Not in this flow | — Not in this flow | — | By design')
        ->and($rows->first(fn (string $row): bool => str_contains($row, '[Primary]')))->toBe('Reached the goal [Primary] | 91.7% 11 of 12 | 83.3% 10 of 12 | −9.1% | No clear difference')
        ->and($data['verdict']['title'])->toBe('No winner yet.')
        ->and($data['verdict']['text'])->toBe('Day 18. Players who reached the goal so far: V8 −9.1% against V7; no version leads with strong evidence.')
        ->and($data['table']['foot'])->toEndWith('The verdict follows players who reached the goal.')
        // Signed-in players make no account and leave no email: no conditional rates to show.
        ->and($data['table']['conditional'])->toBe([]);

    Livewire::withQueryParams(['tab' => 'experiments'])->test(GrowthAnalyticsPage::class)->call('load')
        ->assertDontSee('Conditional rates · secondary');
});

it('counts activation among accounts old enough only, and says while none is', function () {
    $period = comparisonPeriod($this->game, [7 => 50, 8 => 50], '2026-11-01 09:30:00');
    // V7: ten accounts from 05 Nov, four activated; five from 15 Nov, activated, but still within their seven days.
    assignVisitors($period, 7, 15, fn (int $n): array => $n <= 10 ? ['account' => '2026-11-05 10:00:00', 'activated' => $n <= 4] : ['account' => '2026-11-15 10:00:00', 'activated' => true]);
    assignVisitors($period, 8, 15, fn (int $n): array => ['account' => '2026-11-05 10:00:00', 'activated' => $n <= 1]);

    $data = analyticsExperiments();
    $activated = collect($data['table']['rows'])->firstWhere('label', 'Activated within 7 days');

    expect($activated['reference'])->toBe(['rate' => '26.7%', 'count' => '4 of 15', 'none' => false])
        ->and($activated['groups'][0]['count'])->toBe('1 of 15')
        ->and($activated['sub'])->toBe('Mature accounts: 10 · 15 (signed up 8+ days ago)');

    $fresh = comparisonPeriod($this->game, [7 => 50, 8 => 50], '2026-11-14 09:30:00', audience: GameAudience::Anonymous);
    $period->forceFill(['ended_at' => '2026-11-14 09:30:00'])->save();
    assignVisitors($fresh, 7, 2, fn (): array => ['account' => '2026-11-15 10:00:00', 'activated' => true]);
    assignVisitors($fresh, 8, 2, fn (): array => []);

    $data = analyticsExperiments(['exp' => (string) $fresh->id]);

    expect(collect(experimentRows($data))->first(fn (string $row): bool => str_starts_with($row, 'Activated')))->toBe('Activated within 7 days | — Needs 7 days | — Needs 7 days | — | Too early')
        ->and($data['verdict']['text'])->toBe('Day 5. Too early: V7 has 2 of 10 accounts, V8 has 0 of 10 accounts. Activation waits for accounts to have their 7 days.');
});

it('says Paused while the game of a period still open is paused, and flags a split that looks off', function () {
    $this->game->forceFill(['status' => GameStatus::Paused])->save();
    $period = comparisonPeriod($this->game, [7 => 80, 8 => 20], '2026-11-11 09:30:00');
    // Half and half, where the shares say 80 and 20.
    assignVisitors($period, 7, 30, fn (): array => ['started' => true]);
    assignVisitors($period, 8, 30, fn (): array => ['started' => true]);

    $data = analyticsExperiments();

    expect($data['choices'][0]['meta'])->toBe('Paused · day 8')
        ->and($data['status'])->toBe(['label' => 'Paused', 'tone' => 'warning', 'dot' => false])
        ->and($data['facts'][0])->toBe(['label' => 'Duration', 'value' => 'Day 8'])
        ->and($data['verdict'])->toBe(['tone' => 'warning', 'icon' => 'circle-pause', 'kind' => 'paused', 'title' => 'Paused.', 'text' => 'New visitors aren’t being assigned while the game is paused. Results so far are below.'])
        ->and($data['table']['rows'][0]['sub'])->toBe('Split 80/20 · 50.0% · 50.0%')
        ->and($data['table']['rows'][0]['groups'][0]['evidence'])->toBe(['label' => 'Split looks off', 'tone' => 'warning']);

    // Once the period is over, the game's pause is nothing to it.
    $period->forceFill(['ended_at' => '2026-11-16 09:30:00'])->save();
    expect(analyticsExperiments()['verdict']['kind'])->toBe(ComparisonVerdict::ENDED);
});

it('shows a version’s email step and says which versions ask for one', function () {
    $asking = emailAskingVersion($this->game, 8);
    $period = comparisonPeriod($this->game, [7 => 50, 8 => 50], '2026-11-10 09:30:00');
    assignVisitors($period, 8, 4, fn (int $n): array => ['email' => $n <= 3]);
    assignVisitors($period, 7, 4, fn (): array => ['completed' => true]);

    $data = analyticsExperiments();
    $emails = collect($data['table']['rows'])->firstWhere('label', 'Left an email');

    expect($data['variants'][1]['name'])->toBe('Spring set + Email Before Result')
        ->and(array_map(fn (array $step): string => $step['label'].'/'.$step['kind'], $data['variants'][1]['steps']))->toBe(['Landing/step', 'Game/game', 'Result Preview/gate', 'Email Capture/gate', 'Full Result/step'])
        ->and($asking->asksAnonymousForEmail())->toBeTrue()
        ->and($emails['sub'])->toBe('Only V8 asks for one')
        ->and($emails['groups'][0]['count'])->toBe('3 of 4')
        ->and($emails['groups'][0]['evidence']['label'])->toBe('By design')
        ->and($data['table']['conditional'][1]['note'])->toBe('Only V8 has an email step')
        // Every V8 visitor who completed the game left an email.
        ->and($data['table']['conditional'][1]['groups'][0]['rate'])->toBe('100.0%')
        // V8 shows the full result only after its email step.
        ->and(collect($data['table']['rows'])->firstWhere('label', 'Saw the full result')['sub'])->toBe('V8 shows it after the email step');
});

it('says nothing about an email step under the full result where no version asks first', function () {
    $period = comparisonPeriod($this->game, [7 => 50, 8 => 50], '2026-11-10 09:30:00');
    assignVisitors($period, 7, 4, fn (): array => ['completed' => true]);
    assignVisitors($period, 8, 4, fn (): array => ['completed' => true]);

    expect(collect(analyticsExperiments()['table']['rows'])->firstWhere('label', 'Saw the full result')['sub'])->toBe('');
});

it('says so with no comparison yet, and while nobody was sent to a version', function () {
    comparisonPeriod($this->game, [7 => 100], '2026-11-01 09:30:00');

    Livewire::withQueryParams(['tab' => 'experiments'])->test(GrowthAnalyticsPage::class)->call('load')
        ->assertViewHas('state', 'ready')
        ->assertSee('No comparisons yet')
        ->assertSee('A traffic period that gives two or more versions a share compares them here. Shares change in each game’s Traffic tab.')
        ->assertDontSee('Metric · share of assigned');

    $period = comparisonPeriod($this->game, [7 => 50, 8 => 50], '2026-11-18 09:30:00');
    $data = analyticsExperiments();

    expect($data['periodId'])->toBe($period->id)
        ->and($data['fresh'])->toBe(['title' => 'No visitors yet', 'text' => 'This period just started. Numbers appear as visitors arrive.'])
        ->and($data['verdict'])->toBeNull()
        ->and($data['table'])->toBeNull()
        ->and($data['choices'][0]['meta'])->toBe('Current · day 1');

    // A paused game's period nobody was sent to says why, once, with no strip promising results below.
    $this->game->forceFill(['status' => GameStatus::Paused])->save();
    $paused = analyticsExperiments();

    expect($paused['fresh'])->toBe(['title' => 'No visitors yet', 'text' => 'Nobody was sent to a version before the game was paused. Numbers appear once it is live again.'])
        ->and($paused['verdict'])->toBeNull();
});

it('needs no recorded visit, says nothing about recording, and reads a bounded number of queries', function () {
    // Nothing recorded at all, and Record visits off: the tab reads assignments, not visits.
    $period = comparisonPeriod($this->game, [7 => 50, 8 => 50], '2026-11-01 09:30:00');
    assignVisitors($period, 7, 2, fn (): array => ['started' => true]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get(GrowthAnalyticsPage::getUrl(['tab' => 'experiments']))->assertOk()->assertSee('wire:init="load"', false)->assertDontSee('Metric · share of assigned');
    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $query): bool => str_contains($query, 'traffic_visits') || str_contains($query, 'game_assignments'))->values()->all())->toBe([]);
    DB::disableQueryLog();

    Livewire::withQueryParams(['tab' => 'experiments'])->test(GrowthAnalyticsPage::class)->call('load')
        ->assertViewHas('state', 'ready')
        ->assertViewHas('notices', [])
        ->assertSee('Metric · share of assigned');

    $queries = function (array $query): int {
        $page = Livewire::withQueryParams(['tab' => 'experiments', ...$query])->test(GrowthAnalyticsPage::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $page->call('load');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $few = $queries([]);

    foreach (range(1, 15) as $n) {
        $game = Game::factory()->status(GameStatus::Live)->create();
        $each = comparisonPeriod($game, [1 => 40, 2 => 30, 3 => 30], CarbonImmutable::parse('2026-09-01 09:30:00')->addDays($n)->toDateTimeString());
        assignVisitors($each, 2, 2, fn (): array => ['completed' => true]);
    }

    $many = $queries([]);
    // One older than the twelve offered, named by the address: checked, then read with its game besides.
    $older = comparisonPeriod($this->game, [7 => 50, 8 => 50], '2026-06-01 09:30:00', '2026-06-20 09:30:00');
    $named = $queries(['exp' => (string) $older->id]);

    // The comparisons and their games, the chosen one's versions, flows and content sets, its funnel and how far the hours it is summed from are counted, and the shell's filters.
    expect($few)->toBeLessThanOrEqual(11)
        ->and($many)->toBe($few)
        ->and($named)->toBeLessThanOrEqual($few + 3);
});
