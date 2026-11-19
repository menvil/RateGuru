<?php

use App\Enums\TrafficChannel;
use App\Filament\Pages\GrowthAnalyticsPage;
use App\Models\GameAttempt;
use App\Models\GameVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * Analytics → Retention: cohorts of the weeks confirmed new accounts of
 * members signed up in — never a Persona's or staff's, never one not
 * confirmed — starting with the week of the first account recorded, each
 * window "—" until it has matured and "n < 30" below thirty accounts; the
 * period sets New users, Returning users and the Accounts of both tables,
 * the line adding the cohorts up to the period drawn on Last 30 days with
 * every device; the device is the credited visit's, the source the key its
 * session began with; a row cuts the cohorts and a second click shows every
 * account again. The source and campaign filters don't apply, and the page
 * says so; nothing about recorded visits is said. With no account the tab
 * says so once; the page reads nothing of it until it is in a browser, and
 * the tab a bounded number of queries.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00'));
    // The figures as they are at each read: the read cache has tests of its own (AnalyticsReadCacheTest).
    config(['analytics.read_cache_minutes' => 0]);
    $this->actingAs(User::factory()->admin()->create());
});

/** The open Retention tab's figures, read as the page reads them once it is in a browser. */
function analyticsRetention(array $query = []): array
{
    return Livewire::withQueryParams(['tab' => 'retention', ...$query])->test(GrowthAnalyticsPage::class)->call('load')->viewData('data');
}

/** A table's rows as text: the name, what it is, the accounts, the three rates and the returns by day 7. */
function retentionRows(array $table): array
{
    return array_map(fn (array $row): string => implode(' | ', [$row['name'], $row['sub'], $row['accounts'], ...array_column($row['rates'], 'value'), $row['kept']['value']]), $table['rows']);
}

/**
 * Thirty accounts of the week of 12 Oct from Reddit — nine back on day 1,
 * three of them on day 7 too, three others on day 30 — the first fifteen
 * through Trust Your Taste; five of the week of 9 Nov credited to no visit,
 * two back on day 1; two of this week; and accounts that never count.
 */
function retentionFixture(): void
{
    $reddit = analyticsVisit('reddit', ['source_key' => 'reddit:organic', 'device' => 'desktop']);
    $version = GameVersion::factory()->create();
    $version->game->forceFill(['title' => 'Trust Your Taste'])->save();
    $attempt = GameAttempt::factory()->create(['version_id' => $version->id]);

    foreach (range(1, 30) as $n) {
        retentionAccount('2026-10-13 10:00:00', match (true) {
            $n <= 3 => [1, 7],
            $n <= 9 => [1],
            $n <= 12 => [30],
            default => [],
        }, ['credited_visit_id' => $reddit->id, 'credited_attempt_id' => $n <= 15 ? $attempt->id : null]);
    }

    foreach (range(1, 5) as $n) {
        retentionAccount('2026-11-10 10:00:00', $n <= 2 ? [1] : []);
    }

    retentionAccount('2026-11-16 08:00:00');
    retentionAccount('2026-11-17 23:00:00');

    // Never counted: not confirmed, a Persona's, staff's.
    retentionAccount('2026-11-10 10:00:00', [1], ['confirmed_at' => null]);
    retentionAccount('2026-11-10 10:00:00', [1], [], User::factory()->persona()->create());
    retentionAccount('2026-11-10 10:00:00', [1], [], User::factory()->moderator()->create());
}

it('follows the weeks confirmed accounts of members signed up in, from the first, measuring each only once it has matured', function () {
    retentionFixture();

    $data = analyticsRetention();
    $cohorts = $data['cohorts'];

    expect(array_map(fn (array $kpi): string => $kpi['label'].': '.$kpi['value'].' · '.$kpi['note'], $data['kpis']))->toBe([
        'New users: 7 · Accounts created · last 30 days',
        // Back on day 7 (20 Oct) and on day 30 (12 Nov) of 13 Oct, and on day 1 (11 Nov) of 10 Nov.
        'Returning users: 8 · Registered users with a qualifying return',
        'Day 1 retention: 31% · Matured cohorts · 35 users',
        'Day 7 retention: 10% · Matured cohorts · 30 users',
        'Day 30 retention: 10% · Cohorts up to 12 Oct · 30 users',
    ])
        ->and($data['definition'])->toBe('a visit on a later day with at least one vote, comment, post or game played. Having an account, staying logged in or receiving an email doesn’t count.')
        ->and($cohorts['title'])->toBe('Registration cohorts · All new users')
        ->and($cohorts['note'])->toBe('Users who registered each week, and the share who came back on day 1, 7 and 30. Data through 18 Nov. Accounts are followed from 13 Oct 2026, when the first sign-up was recorded. Pick a source or game below to filter.')
        ->and(array_map(fn (array $row): string => implode(' | ', [$row['label'], $row['users'], ...array_column($row['cells'], 'value')]), $cohorts['rows']))->toBe([
            '12 Oct | 30 | 30% | 10% | 10%',
            '19 Oct | 0 | n < 30 | n < 30 | —',
            '26 Oct | 0 | n < 30 | n < 30 | —',
            '02 Nov | 0 | n < 30 | n < 30 | —',
            '09 Nov | 5 | n < 30 | — | —',
            '16 Nov · open | 2 | — | — | —',
        ])
        ->and(array_column($cohorts['rows'], 'note'))->toBe([null, '0 of them from 20 Oct, inside the last 30 days', null, null, null, 'Registrations so far this week'])
        ->and($cohorts['rows'][0]['cells'][0])->toBe(['value' => '30%', 'title' => '9 of 30 users returned', 'tone' => 'heat', 'heat' => 43, 'dark' => true])
        ->and($cohorts['rows'][4]['cells'][1]['title'])->toBe('Not matured: day 7 hasn’t passed for this whole cohort')
        ->and($cohorts['reconciliation'])->toBe('Last 30 days, 20 Oct — 18 Nov 2026: 0 + 0 + 0 + 5 + 2 = 7 accounts, the same as New users above. The 19 Oct week counts from 20 Oct.');

    [$sources, $games] = $data['tables'];

    expect(retentionRows($sources))->toBe([
        // Credited to no visit: Direct / Unknown.
        'Direct / Unknown | (none) | 7 | n < 30 | — | — | —',
        'Reddit | organic_social | 0 | 30% | 10% | 10% | 9 of 30',
    ])
        ->and($sources['answer'])->toBe('Reddit users keep 10% at day 7; no other source has enough users in cohorts old enough to compare.')
        ->and($sources['rows'][0]['rates'][1]['title'])->toBe('No users in cohorts old enough to measure')
        ->and($sources['rows'][1]['kept']['title'])->toBe('9 of 30 users from the 12 Oct — 02 Nov cohorts came back by day 7')
        ->and(retentionRows($games))->toBe([
            'Trust Your Taste | Versus | 0 | n < 30 | n < 30 | n < 30 | n < 30',
            'Other website experiences | No game finished first | 7 | n < 30 | n < 30 | n < 30 | n < 30',
        ])
        ->and($games['answer'])->toBe('No game has 30 users in cohorts old enough to measure day 7 yet.')
        ->and(array_map(fn (array $chip): string => $chip['label'].($chip['on'] ? ' (on)' : ''), $cohorts['chips']))->toBe(['All new users (on)', 'Direct / Unknown · (none)', 'Reddit · organic_social']);
});

it('takes the accounts and the members who came back from the period, the cohorts staying the last nine weeks', function () {
    retentionFixture();

    $data = analyticsRetention(['period' => '7d']);

    expect(array_column($data['kpis'], 'value'))->toBe(['2', '3', '31%', '10%', '10%'])
        ->and($data['kpis'][0]['note'])->toBe('Accounts created · last 7 days')
        ->and(array_column($data['cohorts']['rows'], 'users'))->toBe(['30', '0', '0', '0', '5', '2'])
        // The line adding the cohorts up is drawn on Last 30 days only.
        ->and($data['cohorts']['reconciliation'])->toBeNull()
        ->and(array_column($data['cohorts']['rows'], 'note'))->toBe([null, null, null, null, null, 'Registrations so far this week'])
        ->and(array_column($data['tables'][0]['rows'], 'accounts'))->toBe(['2', '0'])
        ->and(array_column($data['tables'][1]['rows'], 'accounts'))->toBe(['0', '2']);

    $range = analyticsRetention(['from' => '2026-11-09', 'to' => '2026-11-15']);

    expect($range['kpis'][0]['note'])->toBe('Accounts created · 09 Nov — 15 Nov 2026')
        ->and($range['kpis'][0]['value'])->toBe('5')
        ->and($range['tables'][0]['note'])->toStartWith('Accounts: 09 Nov — 15 Nov 2026, by the day they signed up. Rates and returns: every cohort old enough to measure, since 12 Oct.')
        ->and($range['cohorts']['reconciliation'])->toBeNull();
});

it('reads an account’s device from its credited visit and its source from the key its session began with', function () {
    $reddit = analyticsVisit('ann', ['source_key' => 'reddit:organic', 'device' => 'mobile', 'network_day' => '2026-11-10']);
    // A game visit going on from Reddit's landing: its key is the session's.
    $game = analyticsVisit('ann', ['channel' => TrafficChannel::Internal, 'source_key' => 'reddit:organic', 'device' => 'mobile', 'network_day' => '2026-11-10', 'arrived_at' => '2026-11-10 10:05:00']);
    analyticsSession($reddit, $game);
    $desktop = analyticsVisit('bob', ['source_key' => 'google:organic', 'device' => 'desktop', 'network_day' => '2026-11-10']);

    retentionAccount('2026-11-10 10:10:00', [1], ['credited_visit_id' => $game->id]);
    retentionAccount('2026-11-10 11:00:00', [1], ['credited_visit_id' => $desktop->id]);
    retentionAccount('2026-11-11 11:00:00', [1]);

    $everyone = analyticsRetention();
    $mobile = analyticsRetention(['device' => 'mobile']);

    // As many accounts each: by key.
    expect(retentionRows($everyone['tables'][0]))->toBe([
        'Direct / Unknown | (none) | 1 | n < 30 | — | — | —',
        'Google | organic_search | 1 | n < 30 | — | — | —',
        'Reddit | organic_social | 1 | n < 30 | — | — | —',
    ])
        ->and($everyone['kpis'][1]['value'])->toBe('3')
        ->and(retentionRows($mobile['tables'][0]))->toBe(['Reddit | organic_social | 1 | n < 30 | — | — | —'])
        ->and(array_column($mobile['kpis'], 'value'))->toBe(['1', '1', 'n < 30', '—', '—'])
        ->and($mobile['kpis'][0]['note'])->toBe('Accounts created · last 30 days · Mobile')
        ->and($mobile['cohorts']['note'])->toContain('Mobile only: the device of the visit each account is credited to.')
        // The line adding the cohorts up is the reference's on every device only.
        ->and($mobile['cohorts']['reconciliation'])->toBeNull()
        ->and($everyone['cohorts']['reconciliation'])->not->toBeNull();
});

it('cuts the cohorts by a row, a second click showing every account again, the cut in the address', function () {
    retentionFixture();

    $page = Livewire::withQueryParams(['tab' => 'retention'])->test(GrowthAnalyticsPage::class)->call('load');
    $reddit = collect($page->viewData('data')['tables'][0]['rows'])->firstWhere('dim', 'reddit:organic');

    expect($reddit['toggle'])->toBe('reddit:organic')
        ->and($reddit['chosen'])->toBeFalse();

    $page->set('dim', $reddit['toggle'])->assertSet('dim', 'reddit:organic');
    $data = $page->viewData('data');
    $chosen = collect($data['tables'][0]['rows'])->firstWhere('dim', 'reddit:organic');

    expect($data['cohorts']['title'])->toBe('Registration cohorts · Reddit · organic_social')
        ->and(array_column($data['cohorts']['rows'], 'users'))->toBe(['30', '0', '0', '0', '0', '0'])
        ->and($data['cohorts']['reconciliation'])->toBe('Last 30 days, 20 Oct — 18 Nov 2026: 0 + 0 + 0 + 0 + 0 = 0 accounts, the same as the Reddit · organic_social row below. The 19 Oct week counts from 20 Oct.')
        ->and($chosen['chosen'])->toBeTrue()
        ->and($chosen['toggle'])->toBe('')
        ->and(collect($data['cohorts']['chips'])->firstWhere('on', true)['label'])->toBe('Reddit · organic_social')
        // The tiles stay every account's.
        ->and($data['kpis'][2]['value'])->toBe('31%');

    // A game's row adds its chip.
    $game = collect($data['tables'][1]['rows'])->first();
    $page->set('dim', $game['toggle']);
    $data = $page->viewData('data');

    expect($data['cohorts']['title'])->toBe('Registration cohorts · Trust Your Taste')
        ->and(array_column($data['cohorts']['rows'], 'users'))->toBe(['15', '0', '0', '0', '0', '0'])
        ->and(array_map(fn (array $chip): string => $chip['label'].($chip['on'] ? ' (on)' : ''), $data['cohorts']['chips']))->toBe(['All new users', 'Direct / Unknown · (none)', 'Reddit · organic_social', 'Trust Your Taste (on)']);

    $page->set('dim', '')->assertSet('dim', '');

    expect($page->viewData('data')['cohorts']['title'])->toBe('Registration cohorts · All new users');

    // A cut the address names that can't be read, or has no row, is every account; on another tab, none at all.
    Livewire::withQueryParams(['tab' => 'retention', 'dim' => "reddit\n:organic"])->test(GrowthAnalyticsPage::class)->assertSet('dim', '');
    expect(analyticsRetention(['dim' => 'facebook:organic'])['cohorts']['title'])->toBe('Registration cohorts · All new users')
        ->and(analyticsRetention(['dim' => 'game:999'])['cohorts']['title'])->toBe('Registration cohorts · All new users');
    Livewire::withQueryParams(['tab' => 'geo', 'dim' => 'reddit:organic'])->test(GrowthAnalyticsPage::class)->assertSet('dim', '');
});

it('says the source and campaign filters don’t apply, and nothing about recorded visits, reading the same accounts', function () {
    retentionFixture();

    $narrowed = Livewire::withQueryParams(['tab' => 'retention', 'source' => 'google:organic', 'campaign' => 'tag:launch'])->test(GrowthAnalyticsPage::class)->call('load');

    expect(collect($narrowed->viewData('notices'))->pluck('text')->all())->toBe(['The source and campaign filters don’t apply on this tab.'])
        ->and($narrowed->viewData('state'))->toBe('ready')
        ->and($narrowed->viewData('data')['kpis'])->toBe(analyticsRetention()['kpis']);

    $narrowed->assertSee('Retention counts a qualifying return:')->assertSee('Registration cohorts · All new users')->assertSee('Retention by originating game');

    // Recording is off and no visit was ever recorded since: the accounts are still there.
    expect(collect(Livewire::withQueryParams(['tab' => 'retention'])->test(GrowthAnalyticsPage::class)->call('load')->viewData('notices'))->all())->toBe([]);
});

it('says so once when no account matches, offering to clear a device chosen', function () {
    Livewire::withQueryParams(['tab' => 'retention'])->test(GrowthAnalyticsPage::class)->call('load')
        ->assertViewHas('data', ['empty' => true, 'range' => '20 Oct — 18 Nov 2026', 'filtered' => false])
        ->assertSee('No new accounts for these filters')
        ->assertSee('No confirmed account signed up in 20 Oct — 18 Nov 2026 or in the nine weeks the cohorts cover.')
        ->assertDontSee('Clear filters')
        ->assertDontSee('Registration cohorts');

    retentionAccount('2026-11-10 10:00:00');

    Livewire::withQueryParams(['tab' => 'retention', 'device' => 'desktop'])->test(GrowthAnalyticsPage::class)->call('load')
        ->assertViewHas('data', ['empty' => true, 'range' => '20 Oct — 18 Nov 2026', 'filtered' => true])
        ->assertSee('Clear filters')
        ->call('clearFilters')
        ->assertSet('device', '')
        ->assertSee('Registration cohorts · All new users');
});

it('reads nothing of the tab until the page is in a browser, then a bounded number of queries however many sources and games', function () {
    retentionFixture();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get(GrowthAnalyticsPage::getUrl(['tab' => 'retention']))->assertOk()->assertDontSee('Registration cohorts');

    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $query): bool => str_contains($query, 'account_attributions') || str_contains($query, 'user_activity_days'))->values()->all())->toBe([]);
    DB::disableQueryLog();

    $queries = function (array $query): int {
        $page = Livewire::withQueryParams(['tab' => 'retention', ...$query])->test(GrowthAnalyticsPage::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $page->call('load');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $few = $queries([]);

    foreach (range(1, 8) as $n) {
        $visit = analyticsVisit('source-'.$n, ['source_key' => 'host.site'.$n.'.com:referral']);
        $attempt = GameAttempt::factory()->create();

        foreach (range(1, 3) as $each) {
            retentionAccount('2026-11-0'.$each.' 10:00:00', [1, 7], ['credited_visit_id' => $visit->id, 'credited_attempt_id' => $attempt->id]);
        }
    }

    $many = $queries([]);
    $cut = $queries(['dim' => 'other-sources', 'device' => 'desktop']);

    // The shell's filters, the first account, the cohorts' one read, the members who came back, the games' and paid sources' names.
    expect($few)->toBeLessThanOrEqual(8)
        ->and($many)->toBeLessThanOrEqual(8)
        ->and($cut)->toBeLessThanOrEqual(8)
        ->and($many - $few)->toBeLessThanOrEqual(1);
});
