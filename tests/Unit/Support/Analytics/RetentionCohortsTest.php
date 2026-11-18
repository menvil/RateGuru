<?php

use App\Support\Analytics\RetentionCohorts;
use App\Support\Analytics\RetentionShare;
use Carbon\CarbonImmutable;

/*
 * Retention worked out from the accounts read: weeks from Monday, none
 * before the week of the first account; a cohort measured on a day only
 * once that day is over for all of it, "n < 30" below thirty accounts; the
 * matured cohorts taken together; the five sources and games with the most
 * accounts of the period, the rest together, adding up to every account of
 * the period; the line adding the cohorts up to the period; and what the
 * rows say together.
 */

/** The nine weeks to Wednesday 18 Nov 2026. */
const RETENTION_WEEKS = ['2026-09-21', '2026-09-28', '2026-10-05', '2026-10-12', '2026-10-19', '2026-10-26', '2026-11-02', '2026-11-09', '2026-11-16'];

/**
 * A group of accounts, its weeks by place as "users d1 d7 d30 by7 inPeriod",
 * every other week empty.
 *
 * @param  array<int, string>  $weeks
 */
function cohortGroup(?string $source, ?int $game, int $accounts, array $weeks): array
{
    $read = [];

    foreach (array_keys(RETENTION_WEEKS) as $index) {
        [$users, $d1, $d7, $d30, $by7, $in] = array_map('intval', explode(' ', $weeks[$index] ?? '0 0 0 0 0 0'));
        $read[] = ['users' => $users, 'd1' => $d1, 'd7' => $d7, 'd30' => $d30, 'by7' => $by7, 'inPeriod' => $in];
    }

    return ['source' => $source, 'game' => $game, 'accounts' => $accounts, 'weeks' => $read];
}

/** The cohorts of the groups, read on a day, for the last 30 days unless another period is given. */
function retentionCohorts(array $groups, string $today = '2026-11-18', string $from = '2026-10-20', string $to = '2026-11-18'): RetentionCohorts
{
    return new RetentionCohorts(RETENTION_WEEKS, $groups, $today, $from, $to, 30);
}

/** A share as the tab reads it: "—", "n < 30" or a whole percent, with its counts. */
function shareRead(?RetentionShare $share): string
{
    return match (true) {
        $share === null => '—',
        $share->isSmall() => 'n < 30 ('.$share->returned.'/'.$share->users.')',
        default => $share->percent().'% ('.$share->returned.'/'.$share->users.')',
    };
}

it('takes this week and the eight before it from Monday, none before the week of the first account', function () {
    $wednesday = CarbonImmutable::parse('2026-11-18 23:30:00', 'UTC');

    expect(RetentionCohorts::weekStarts($wednesday, null))->toBe(RETENTION_WEEKS)
        ->and(RetentionCohorts::weekStarts($wednesday, CarbonImmutable::parse('2026-01-01', 'UTC')))->toBe(RETENTION_WEEKS)
        // A first account on a Sunday counts from its Monday.
        ->and(RetentionCohorts::weekStarts($wednesday, CarbonImmutable::parse('2026-10-11 22:00:00', 'UTC')))->toBe(['2026-10-05', '2026-10-12', '2026-10-19', '2026-10-26', '2026-11-02', '2026-11-09', '2026-11-16'])
        ->and(RetentionCohorts::weekStarts(CarbonImmutable::parse('2026-11-16 00:00:00', 'UTC'), CarbonImmutable::parse('2026-11-16 00:00:00', 'UTC')))->toBe(['2026-11-16'])
        // A clock behind the first account still has this week.
        ->and(RetentionCohorts::weekStarts($wednesday, CarbonImmutable::parse('2026-11-25', 'UTC')))->toBe(['2026-11-16']);

    expect(retentionCohorts([])->isEmpty())->toBeTrue()
        ->and(new RetentionCohorts(['2026-11-09', '2026-11-16'], [], '2026-11-18', '2026-11-12', '2026-11-18', 30)->startsLate())->toBeTrue()
        ->and(retentionCohorts([])->startsLate())->toBeFalse();
});

it('measures a cohort on a day only once that day is over for every account of it, and says n < 30 below thirty', function () {
    $cohorts = retentionCohorts([cohortGroup('reddit:organic', null, 0, [
        3 => '40 12 6 4 15 0',
        4 => '29 9 3 1 9 9',
        6 => '50 20 10 0 25 50',
        7 => '60 30 0 0 31 60',
        8 => '10 0 0 0 0 10',
    ])]);
    $rows = $cohorts->cohorts();
    $cells = fn (int $index): array => array_map('shareRead', $rows[$index]['cells']);

    expect(array_column($rows, 'start'))->toBe(RETENTION_WEEKS)
        ->and(array_column($rows, 'open'))->toBe([false, false, false, false, false, false, false, false, true])
        ->and(array_column($rows, 'users'))->toBe([0, 0, 0, 40, 29, 0, 50, 60, 10])
        // Day 30 of 12 Oct's Sunday is 17 Nov: over by the 18th.
        ->and($cells(3))->toBe([1 => '30% (12/40)', 7 => '15% (6/40)', 30 => '10% (4/40)'])
        ->and($cells(4))->toBe([1 => 'n < 30 (9/29)', 7 => 'n < 30 (3/29)', 30 => '—'])
        ->and($cells(6))->toBe([1 => '40% (20/50)', 7 => '20% (10/50)', 30 => '—'])
        // Day 7 of 9 Nov's Sunday is 22 Nov.
        ->and($cells(7))->toBe([1 => '50% (30/60)', 7 => '—', 30 => '—'])
        ->and($cells(8))->toBe([1 => '—', 7 => '—', 30 => '—'])
        // Only the weeks reaching into the period say how many of them are inside it.
        ->and(array_column($rows, 'inPeriod'))->toBe([null, null, null, null, 9, 0, 50, 60, 10]);

    // On the 16th, day 1 of 9 Nov's Sunday — the 16th — isn't over yet.
    expect(retentionCohorts([cohortGroup(null, null, 0, [7 => '60 30 0 0 31 0'])], today: '2026-11-16')->cohorts()[7]['cells'][1])->toBeNull()
        ->and(retentionCohorts([cohortGroup(null, null, 0, [7 => '60 30 0 0 31 0'])], today: '2026-11-17')->cohorts()[7]['cells'][1])->not->toBeNull();
});

it('takes every matured cohort together for each window and for returning by day 7', function () {
    $cohorts = retentionCohorts([
        cohortGroup('reddit:organic', null, 5, [0 => '20 5 2 1 6 0', 3 => '20 4 4 3 8 0', 6 => '30 9 3 0 12 30', 7 => '30 15 0 0 15 30']),
        cohortGroup('google:organic', null, 3, [3 => '5 1 1 1 2 0']),
    ]);

    expect(array_map('shareRead', $cohorts->pooled()))->toBe([
        1 => '32% (34/105)',
        7 => '13% (10/75)',
        30 => '11% (5/45)',
        'by' => '37% (28/75)',
    ])
        ->and(array_map('shareRead', $cohorts->pooled('google:organic')))->toBe([1 => 'n < 30 (1/5)', 7 => 'n < 30 (1/5)', 30 => 'n < 30 (1/5)', 'by' => 'n < 30 (2/5)'])
        ->and($cohorts->lastMatured(1))->toBe('2026-11-09')
        ->and($cohorts->lastMatured(7))->toBe('2026-11-02')
        ->and($cohorts->lastMatured(30))->toBe('2026-10-12');

    // No cohort matured yet: nothing to take together.
    $young = new RetentionCohorts(['2026-11-16'], [['source' => null, 'game' => null, 'accounts' => 4, 'weeks' => [['users' => 4, 'd1' => 0, 'd7' => 0, 'd30' => 0, 'by7' => 0, 'inPeriod' => 4]]]], '2026-11-18', '2026-10-20', '2026-11-18', 30);

    expect($young->pooled())->toBe([1 => null, 7 => null, 30 => null, 'by' => null])
        ->and($young->anyMatured(1))->toBeFalse()
        ->and($young->lastMatured(30))->toBeNull();
});

it('gives the five sources and games with the most accounts of the period a row each, the rest together, adding up to every account', function () {
    $cohorts = retentionCohorts([
        cohortGroup('reddit:organic', 7, 10, [6 => '12 0 0 0 0 10']),
        cohortGroup('reddit:organic', null, 5, [6 => '5 0 0 0 0 5']),
        cohortGroup('google:organic', 8, 9, [6 => '9 0 0 0 0 9']),
        // Credited to no visit: Direct / Unknown, with the direct visits.
        cohortGroup(null, null, 4, [6 => '4 0 0 0 0 4']),
        cohortGroup('direct', 9, 3, [6 => '3 0 0 0 0 3']),
        cohortGroup('facebook:organic', 10, 6, [6 => '6 0 0 0 0 6']),
        cohortGroup('paid:3', 11, 6, [6 => '6 0 0 0 0 6']),
        // As many accounts of the period as Facebook's: more in the cohorts.
        cohortGroup('x:organic', 12, 6, [3 => '30 0 0 0 0 0', 6 => '6 0 0 0 0 6']),
        cohortGroup('bing:organic', 13, 1, [6 => '1 0 0 0 0 1']),
        // None of the period's: the cohorts alone rank it.
        cohortGroup('email:email', 7, 0, [2 => '2 0 0 0 0 0']),
    ]);

    expect($cohorts->topSources())->toBe(['reddit:organic', 'google:organic', 'direct', 'x:organic', 'facebook:organic'])
        ->and(array_map(fn (array $row): string => $row['dim'].' '.$row['accounts'], $cohorts->sourceRows()))->toBe([
            'reddit:organic 15', 'google:organic 9', 'direct 7', 'x:organic 6', 'facebook:organic 6', 'other-sources 7',
        ])
        ->and(array_sum(array_column($cohorts->sourceRows(), 'accounts')))->toBe($cohorts->accounts())
        ->and($cohorts->accounts())->toBe(50)
        ->and($cohorts->topGames())->toBe([7, 8, 12, 10, 11])
        ->and(array_map(fn (array $row): string => $row['dim'].' '.$row['kind'].' '.$row['accounts'], $cohorts->gameRows()))->toBe([
            'game:7 game 10', 'game:8 game 9', 'game:12 game 6', 'game:10 game 6', 'game:11 game 6', 'other-games others 4', 'no-game none 9',
        ])
        ->and(array_sum(array_column($cohorts->gameRows(), 'accounts')))->toBe(50)
        ->and(array_column($cohorts->gameRows(), 'game'))->toBe([7, 8, 12, 10, 11, null, null]);

    // Each row is a cut of the cohorts; anything else is every account.
    expect($cohorts->dims())->toBe(['reddit:organic', 'google:organic', 'direct', 'x:organic', 'facebook:organic', 'other-sources', 'game:7', 'game:8', 'game:12', 'game:10', 'game:11', 'other-games', 'no-game'])
        ->and($cohorts->read('x:organic'))->toBe('x:organic')
        ->and($cohorts->read('paid:3'))->toBe(RetentionCohorts::ALL)
        ->and($cohorts->read('game:13'))->toBe(RetentionCohorts::ALL)
        ->and($cohorts->accounts('other-sources'))->toBe(7)
        ->and(array_column($cohorts->cohorts('game:12'), 'users'))->toBe([0, 0, 0, 30, 0, 0, 6, 0, 0])
        ->and(array_column($cohorts->cohorts('no-game'), 'users'))->toBe([0, 0, 0, 0, 0, 0, 9, 0, 0])
        ->and(RetentionCohorts::gameOf('game:12'))->toBe(12)
        ->and(RetentionCohorts::gameOf('direct'))->toBeNull();

    // With five sources or fewer there is no rest; with no game at all, only the accounts of none.
    $few = retentionCohorts([cohortGroup('reddit:organic', null, 2, [6 => '2 0 0 0 0 2'])]);

    expect(array_column($few->sourceRows(), 'dim'))->toBe(['reddit:organic'])
        ->and(array_column($few->gameRows(), 'dim'))->toBe(['no-game'])
        ->and($few->dims())->toBe(['reddit:organic', 'no-game']);
});

it('adds the cohorts reaching into the period up to its accounts, the first counting from the period’s first day', function () {
    $groups = [
        cohortGroup('reddit:organic', null, 30, [4 => '20 0 0 0 0 9', 5 => '7 0 0 0 0 7', 6 => '6 0 0 0 0 6', 7 => '5 0 0 0 0 5', 8 => '3 0 0 0 0 3']),
        cohortGroup('google:organic', null, 2, [6 => '2 0 0 0 0 2']),
    ];

    expect(retentionCohorts($groups)->reconciliation())->toBe([
        'weeks' => [
            ['start' => '2026-10-19', 'accounts' => 9],
            ['start' => '2026-10-26', 'accounts' => 7],
            ['start' => '2026-11-02', 'accounts' => 8],
            ['start' => '2026-11-09', 'accounts' => 5],
            ['start' => '2026-11-16', 'accounts' => 3],
        ],
        'total' => 32,
        'partial' => '2026-10-19',
    ])
        ->and(retentionCohorts($groups)->reconciliation('google:organic'))->toBe(['weeks' => [['start' => '2026-10-19', 'accounts' => 0], ['start' => '2026-10-26', 'accounts' => 0], ['start' => '2026-11-02', 'accounts' => 2], ['start' => '2026-11-09', 'accounts' => 0], ['start' => '2026-11-16', 'accounts' => 0]], 'total' => 2, 'partial' => '2026-10-19'])
        // A period from a Monday has no week reaching back before it.
        ->and(retentionCohorts([cohortGroup(null, null, 3, [7 => '3 0 0 0 0 3'])], from: '2026-11-09', to: '2026-11-15')->reconciliation()['partial'])->toBeNull()
        // A period outside the weeks has nothing to add up.
        ->and(retentionCohorts([cohortGroup(null, null, 4, [])], from: '2026-08-01', to: '2026-08-31')->reconciliation())->toBeNull();
});

it('says which rows come back most and least by day 7, among those with enough accounts', function () {
    $share = fn (int $returned, int $users): RetentionShare => new RetentionShare($returned, $users, 30);

    expect(RetentionCohorts::sourcePhrase([
        ['name' => 'Reddit', 'share' => $share(27, 100)],
        ['name' => 'Google', 'share' => $share(12, 100)],
        ['name' => 'Email', 'share' => $share(35, 100)],
        ['name' => 'Facebook', 'share' => $share(5, 20)],
        ['name' => 'X', 'share' => null],
    ], 30))->toBe('Email users return most (35% at day 7), Google users least (12%).')
        ->and(RetentionCohorts::sourcePhrase([['name' => 'Reddit', 'share' => $share(27, 100)], ['name' => 'X', 'share' => $share(1, 10)]], 30))
        ->toBe('Reddit users keep 27% at day 7; no other source has enough users in cohorts old enough to compare.')
        ->and(RetentionCohorts::sourcePhrase([['name' => 'X', 'share' => null]], 30))->toBe('No source has 30 users in cohorts old enough to measure day 7 yet.')
        // Rates that read alike are said alike.
        ->and(RetentionCohorts::sourcePhrase([['name' => 'Reddit', 'share' => $share(100, 1000)], ['name' => 'Google', 'share' => $share(101, 1000)]], 30))->toBe('Every source with enough users keeps 10% at day 7.')
        ->and(RetentionCohorts::gamePhrase([['name' => 'Trust Your Taste', 'share' => $share(21, 100)], ['name' => 'Other website experiences', 'share' => $share(25, 100)]], 30))
        ->toBe('Other website experiences keeps the most users at day 7 (25%), Trust Your Taste the fewest (21%).')
        ->and(RetentionCohorts::gamePhrase([['name' => 'Trust Your Taste', 'share' => $share(21, 100)]], 30))->toBe('Trust Your Taste keeps 21% of its users at day 7; nothing else has enough users in cohorts old enough to compare.')
        ->and(RetentionCohorts::gamePhrase([], 30))->toBe('No game has 30 users in cohorts old enough to measure day 7 yet.');

    expect($share(29, 29)->rate())->toBeNull()
        ->and($share(29, 29)->isSmall())->toBeTrue()
        ->and($share(10, 30)->percent())->toBe(33)
        ->and($share(0, 30)->rate())->toBe(0.0)
        ->and(new RetentionShare(0, 0, 0)->isSmall())->toBeTrue();
});
