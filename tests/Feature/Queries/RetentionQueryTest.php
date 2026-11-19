<?php

use App\Enums\TrafficDevice;
use App\Models\GameAttempt;
use App\Models\User;
use App\Models\UserActivityDay;
use App\Queries\Analytics\RetentionQuery;
use Carbon\CarbonImmutable;

/*
 * The raw-SQL boundary of Analytics → Retention: the confirmed new accounts
 * of members — never a Persona or a member of staff, never one not confirmed
 * — of each week given and of the period, per source key of the visit each
 * is credited to and game of the attempt it is credited to, with how many
 * came back on day 1, day 7, day 30 and any of days 1–7, every week's
 * boundaries to the second; the members who came back on a day of the
 * period; and the first account recorded.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00'));
});

/** The weeks of November read in these cases, each by its Monday. */
function retentionWeeks(): array
{
    return array_map(fn (string $day): CarbonImmutable => CarbonImmutable::parse($day, 'UTC'), ['2026-11-02', '2026-11-09', '2026-11-16']);
}

/** The groups read, by "source | game", the weeks as "users d1 d7 d30 by7 inPeriod". */
function retentionGroups(array $groups): array
{
    $read = [];

    foreach ($groups as $group) {
        $read[($group['source'] ?? '-').' | '.($group['game'] ?? '-')] = [
            'accounts' => $group['accounts'],
            'weeks' => array_map(fn (array $week): string => implode(' ', [$week['users'], $week['d1'], $week['d7'], $week['d30'], $week['by7'], $week['inPeriod']]), $group['weeks']),
        ];
    }

    ksort($read);

    return $read;
}

it('counts the confirmed accounts of members per source and game, each week to the second, with the days they came back', function () {
    $reddit = analyticsVisit('ann', ['source_key' => 'reddit:organic', 'device' => 'mobile']);
    $attempt = GameAttempt::factory()->create();

    retentionAccount('2026-11-02 00:00:00', [1, 7], ['credited_visit_id' => $reddit->id]);
    // The week's last second, back only on day 30 — and on day 0, which is no return.
    retentionAccount('2026-11-08 23:59:59', [0, 30], ['credited_visit_id' => $reddit->id]);
    retentionAccount('2026-11-09 00:00:00', [3], ['credited_visit_id' => $reddit->id, 'credited_attempt_id' => $attempt->id]);
    // Credited to no visit: no source.
    retentionAccount('2026-11-12 10:00:00', [1, 2]);
    retentionAccount('2026-11-17 10:00:00', [], ['credited_visit_id' => $reddit->id]);

    // Not counted: not confirmed, a Persona's, staff's, and one before the weeks and the period.
    retentionAccount('2026-11-10 10:00:00', [1], ['confirmed_at' => null]);
    retentionAccount('2026-11-10 10:00:00', [1], [], User::factory()->persona()->create());
    retentionAccount('2026-11-10 10:00:00', [1], [], User::factory()->admin()->create());
    retentionAccount('2026-10-20 10:00:00', [1]);

    $groups = app(RetentionQuery::class)->groups(retentionWeeks(), analyticsFilter('7d')->period, null);

    expect(retentionGroups($groups))->toBe([
        '- | -' => ['accounts' => 1, 'weeks' => ['0 0 0 0 0 0', '1 1 0 0 1 1', '0 0 0 0 0 0']],
        'reddit:organic | -' => ['accounts' => 1, 'weeks' => ['2 1 1 1 1 0', '0 0 0 0 0 0', '1 0 0 0 0 1']],
        'reddit:organic | '.$attempt->game_id => ['accounts' => 0, 'weeks' => ['0 0 0 0 0 0', '1 0 0 0 1 0', '0 0 0 0 0 0']],
    ]);
});

it('reads the accounts of a period outside the weeks, and only those of the device of their credited visit', function () {
    $desktop = analyticsVisit('ann', ['source_key' => 'google:organic', 'device' => 'desktop']);
    $mobile = analyticsVisit('bob', ['source_key' => 'google:organic', 'device' => 'mobile']);

    retentionAccount('2026-08-20 10:00:00', [1], ['credited_visit_id' => $desktop->id]);
    retentionAccount('2026-08-21 10:00:00', [], ['credited_visit_id' => $mobile->id]);
    retentionAccount('2026-11-10 10:00:00', [7], ['credited_visit_id' => $desktop->id]);
    retentionAccount('2026-11-11 10:00:00', [7], ['credited_visit_id' => $mobile->id]);
    // No visit, no device: only on every device.
    retentionAccount('2026-11-12 10:00:00', [7]);

    $august = analyticsFilter(from: '2026-08-15', to: '2026-08-31')->period;
    $query = app(RetentionQuery::class);

    expect(retentionGroups($query->groups(retentionWeeks(), $august, null)))->toBe([
        '- | -' => ['accounts' => 0, 'weeks' => ['0 0 0 0 0 0', '1 0 1 0 1 0', '0 0 0 0 0 0']],
        'google:organic | -' => ['accounts' => 2, 'weeks' => ['0 0 0 0 0 0', '2 0 2 0 2 0', '0 0 0 0 0 0']],
    ])
        ->and(retentionGroups($query->groups(retentionWeeks(), $august, TrafficDevice::Desktop)))->toBe([
            'google:organic | -' => ['accounts' => 1, 'weeks' => ['0 0 0 0 0 0', '1 0 1 0 1 0', '0 0 0 0 0 0']],
        ])
        ->and($query->groups([], $august, null))->toBe([]);
});

it('counts once each member who came back on a day of the period, confirmed or from before accounts were recorded', function () {
    $desktop = analyticsVisit('ann', ['device' => 'desktop']);
    $back = User::factory()->create();
    retentionAccount('2026-11-01 10:00:00', [13, 15], ['credited_visit_id' => $desktop->id], $back);

    // A member from before accounts were recorded, numbered from the day they joined.
    $old = User::factory()->create();
    UserActivityDay::factory()->create(['user_id' => $old->id, 'day' => '2026-11-16', 'day_number' => 400]);

    // Not counted: not confirmed, back only on the day of signing up, back before the period, staff.
    retentionAccount('2026-11-14 10:00:00', [1], ['confirmed_at' => null]);
    retentionAccount('2026-11-15 10:00:00', [0]);
    retentionAccount('2026-11-01 10:00:00', [2]);
    UserActivityDay::factory()->create(['user_id' => User::factory()->admin()->create()->id, 'day' => '2026-11-16', 'day_number' => 3]);

    $query = app(RetentionQuery::class);
    $week = analyticsFilter('7d')->period;

    expect($query->returning($week, null))->toBe(2)
        ->and($query->returning($week, TrafficDevice::Desktop))->toBe(1)
        ->and($query->returning($week, TrafficDevice::Mobile))->toBe(0);
});

it('knows when the first account signed up, confirmed since or not', function () {
    expect(app(RetentionQuery::class)->firstSignup())->toBeNull();

    retentionAccount('2026-10-05 10:00:00');
    retentionAccount('2026-09-30 08:15:00', [], ['confirmed_at' => null]);

    expect(app(RetentionQuery::class)->firstSignup()?->toDateTimeString())->toBe('2026-09-30 08:15:00');
});
