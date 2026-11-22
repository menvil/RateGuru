<?php

use App\Enums\TrafficDevice;
use App\Enums\UserRole;
use App\Models\AccountAttribution;
use App\Models\GameAttempt;
use App\Models\GameVersion;
use App\Models\TrafficVisit;
use App\Models\User;
use App\Models\UserActivityDay;
use App\Queries\Analytics\RetentionQuery;
use App\Support\Analytics\AnalyticsPeriod;
use App\Support\Analytics\RetentionCohorts;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/*
 * The raw-SQL boundary of Analytics → Retention: the confirmed new accounts
 * of members — never a Persona or a member of staff, never one not confirmed
 * — of each week given and of the period, per source key of the visit each
 * is credited to and game of the attempt it is credited to, with how many
 * came back on day 1, day 7, day 30 and any of days 1–7, every week's
 * boundaries to the second; the members who came back on a day of the
 * period; and the first account recorded. Every call reads the same as it
 * did through the visits, before the source, device and game were copied
 * onto the attributions — except that an account whose credited visit was
 * purged keeps them.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00'));
});

/**
 * RetentionQuery as it read before what an account is credited to was
 * copied onto its attribution: the source and device joined from the
 * credited visit — from the visits, or from Analytics' own rows of them —
 * and the game from the credited attempt, the weeks counted here in PHP
 * from one row per account.
 */
function retentionThroughVisits(string $visits = 'traffic_visits'): object
{
    return new class($visits)
    {
        public function __construct(private readonly string $visits) {}

        /** @param  list<CarbonImmutable>  $weeks */
        public function groups(array $weeks, AnalyticsPeriod $period, ?TrafficDevice $device): array
        {
            if ($weeks === []) {
                return [];
            }

            $between = fn (CarbonImmutable $from, CarbonImmutable $to): Closure => fn (Builder $query) => $query
                ->where('accounts.signed_up_at', '>=', $from->format('Y-m-d H:i:s'))
                ->where('accounts.signed_up_at', '<', $to->format('Y-m-d H:i:s'));
            $query = AccountAttribution::query()
                ->from('account_attributions as accounts')
                ->join('users', 'users.id', '=', 'accounts.user_id')
                ->leftJoin($this->visits.' as credited', 'credited.id', '=', 'accounts.credited_visit_id')
                ->leftJoin('game_attempts as attempts', 'attempts.id', '=', 'accounts.credited_attempt_id')
                ->where('users.role', UserRole::User->value)
                ->whereNotNull('accounts.confirmed_at')
                ->where(fn (Builder $query) => $query->where($between($weeks[0], end($weeks)->addDays(7)))->orWhere($between($period->start(), $period->end())))
                ->when($device !== null, fn (Builder $query) => $query->where('credited.device', $device?->value))
                ->select('accounts.signed_up_at', 'credited.source_key as source_key', 'attempts.game_id as game_id');

            foreach (RetentionQuery::RETURNS as $return => [$first, $last]) {
                $query->selectSub(UserActivityDay::query()->select('user_activity_days.user_id')->whereColumn('user_activity_days.user_id', 'accounts.user_id')->whereBetween('user_activity_days.day_number', [$first, $last])->limit(1), $return);
            }

            $groups = [];

            foreach ($query->toBase()->get() as $row) {
                $at = CarbonImmutable::parse((string) $row->signed_up_at, 'UTC');
                $in = fn (CarbonImmutable $from, CarbonImmutable $to): bool => $at->greaterThanOrEqualTo($from) && $at->lessThan($to);
                $source = is_string($row->source_key) && $row->source_key !== '' ? $row->source_key : null;
                $game = $row->game_id === null ? null : (int) $row->game_id;
                $group = &$groups[$source.'|'.$game];
                $group ??= ['source' => $source, 'game' => $game, 'accounts' => 0, 'weeks' => array_fill(0, count($weeks), ['users' => 0, 'd1' => 0, 'd7' => 0, 'd30' => 0, 'by7' => 0, 'inPeriod' => 0])];

                foreach ($weeks as $index => $start) {
                    if ($in($start, $start->addDays(7))) {
                        $group['weeks'][$index]['users']++;

                        foreach (array_keys(RetentionQuery::RETURNS) as $return) {
                            $group['weeks'][$index][$return] += $row->{$return} === null ? 0 : 1;
                        }
                    }

                    $group['weeks'][$index]['inPeriod'] += $in($start->max($period->start()), $start->addDays(7)->min($period->end())) ? 1 : 0;
                }

                $group['accounts'] += $in($period->start(), $period->end()) ? 1 : 0;
                unset($group);
            }

            return array_values($groups);
        }

        public function returning(AnalyticsPeriod $period, ?TrafficDevice $device): int
        {
            return UserActivityDay::query()
                ->from('user_activity_days as days')
                ->join('users', 'users.id', '=', 'days.user_id')
                ->leftJoin('account_attributions as accounts', 'accounts.user_id', '=', 'days.user_id')
                ->when($device !== null, fn (Builder $query) => $query
                    ->join('traffic_visits as credited', 'credited.id', '=', 'accounts.credited_visit_id')
                    ->where('credited.device', $device?->value))
                ->where('users.role', UserRole::User->value)
                ->whereBetween('days.day', [$period->fromDay, $period->toDay])
                ->where('days.day_number', '>=', 1)
                ->where(fn (Builder $query) => $query->whereNull('accounts.id')->orWhereNotNull('accounts.confirmed_at'))
                ->distinct()
                ->count('days.user_id');
        }
    };
}

/** Groups read, in one order whatever order the database gave them in. */
function retentionSorted(array $groups): array
{
    usort($groups, fn (array $a, array $b): int => [$a['source'] ?? '', $a['game'] ?? 0] <=> [$b['source'] ?? '', $b['game'] ?? 0]);

    return $groups;
}

/**
 * RetentionQuery read for every call, and read again as it was through the
 * visits (retentionThroughVisits()), from the visits and — once filled —
 * from Analytics' own rows of them: each must read the same, and is what
 * the call returns.
 */
function retentionReadBothWays(): object
{
    return new class
    {
        /** @param  array<int, mixed>  $arguments */
        public function __call(string $method, array $arguments): mixed
        {
            $read = app(RetentionQuery::class)->{$method}(...$arguments);

            if ($method === 'groups') {
                expect(retentionSorted($read))->toEqual(retentionSorted(retentionThroughVisits()->groups(...$arguments)), 'RetentionQuery::groups() reads the copies differently from the visits');
                fillAnalyticsRollup();
                expect(retentionSorted($read))->toEqual(retentionSorted(retentionThroughVisits('analytics_visits')->groups(...$arguments)), "RetentionQuery::groups() reads the copies differently from Analytics' own rows");
            }

            if ($method === 'returning') {
                expect($read)->toBe(retentionThroughVisits()->returning(...$arguments), 'RetentionQuery::returning() reads the copies differently from the visits');
            }

            return $read;
        }
    };
}

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

    $groups = retentionReadBothWays()->groups(retentionWeeks(), analyticsFilter('7d')->period, null);

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
    $query = retentionReadBothWays();

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

    $query = retentionReadBothWays();
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

/**
 * Accounts of every kind Retention reads or leaves out, spread over the
 * nine weeks and the 93 days before today: of members, Personas and staff,
 * confirmed or not, credited to visits of every source and device or to
 * none, to attempts of three games or to none, back on days around 1, 7 and
 * 30 or never — the same every run.
 */
function retentionSpread(): void
{
    mt_srand(20261122);
    $visits = [];

    foreach (['reddit:organic', 'google:organic', 'paid:7', 'direct', null] as $s => $source) {
        foreach (['mobile', 'desktop'] as $d => $device) {
            $visits[] = analyticsVisit('spread-'.$s.'-'.$d, ['source_key' => $source, 'device' => $device])->id;
        }
    }

    $attempts = GameVersion::factory()->count(3)->create()->flatMap(fn (GameVersion $version) => GameAttempt::factory()->count(2)->create(['version_id' => $version->id]))->pluck('id')->all();
    $users = [fn () => User::factory()->create(), fn () => User::factory()->persona()->create(), fn () => User::factory()->admin()->create()];

    for ($n = 0; $n < 70; $n++) {
        $returns = array_values(array_filter([0, 1, 2, 6, 7, 8, 29, 30, 31], fn (): bool => mt_rand(1, 3) === 1));
        $signedUp = CarbonImmutable::parse('2026-11-18 23:59:59', 'UTC')->subSeconds(mt_rand(0, 100 * 86400));

        retentionAccount($signedUp->toDateTimeString(), $returns, [
            'credited_visit_id' => mt_rand(1, 5) === 1 ? null : $visits[mt_rand(0, count($visits) - 1)],
            'credited_attempt_id' => mt_rand(1, 3) === 1 ? null : $attempts[mt_rand(0, count($attempts) - 1)],
            'confirmed_at' => mt_rand(1, 6) === 1 ? null : $signedUp,
        ], $users[mt_rand(1, 10) <= 8 ? 0 : mt_rand(1, 2)]());
    }

    // Members from before accounts were recorded, back now and then.
    foreach (range(1, 4) as $day) {
        UserActivityDay::factory()->create(['user_id' => User::factory()->create()->id, 'day' => '2026-11-1'.$day, 'day_number' => 300 + $day]);
    }
}

/**
 * Every read of Retention the tab can ask for, made both ways: each period,
 * with every device and each. Returns how many groups and returning members
 * were read in all.
 *
 * @return array{groups: int, returning: int}
 */
function retentionReadEveryWay(): array
{
    $query = retentionReadBothWays();
    $first = app(RetentionQuery::class)->firstSignup();
    $read = ['groups' => 0, 'returning' => 0];

    foreach (['7d', '30d', '90d', 'this-month', 'last-month'] as $preset) {
        $filter = analyticsFilter($preset);
        // A name the picker does not offer falls back to its default period and reads it again.
        expect($filter->period->preset)->toBe($preset);
        $weeks = array_map(fn (string $day): CarbonImmutable => CarbonImmutable::parse($day, 'UTC'), RetentionCohorts::weekStarts($filter->period->today, $first));

        foreach ([null, TrafficDevice::Mobile, TrafficDevice::Desktop] as $device) {
            $read['groups'] += count($query->groups($weeks, $filter->period, $device));
            $read['returning'] += $query->returning($filter->period, $device);
        }
    }

    return $read;
}

it('reads every period and device as it did through the visits, the copies written as accounts are credited', function () {
    retentionSpread();

    expect(AccountAttribution::query()->whereNotNull('credited_visit_id')->whereNull('credited_device')->exists())->toBeFalse()
        ->and(retentionReadEveryWay())->groups->toBeGreaterThan(100)->returning->toBeGreaterThan(20);
});

it('reads every period and device as it did through the visits, the copies made from the visits by the migration', function () {
    retentionSpread();
    accountCreditedKeysMigration()->down();
    accountCreditedKeysMigration()->up();

    expect(AccountAttribution::query()->whereNotNull('credited_device')->count())->toBeGreaterThan(30)
        ->and(retentionReadEveryWay())->groups->toBeGreaterThan(100)->returning->toBeGreaterThan(20);
});

it('keeps an account’s source and device once its credited visit is purged, which reading through the visits lost', function () {
    $visit = analyticsVisit('ann', ['source_key' => 'reddit:organic', 'device' => 'desktop']);
    retentionAccount('2026-11-10 10:00:00', [1, 3], ['credited_visit_id' => $visit->id]);
    $week = analyticsFilter('7d')->period;
    $before = app(RetentionQuery::class)->groups(retentionWeeks(), $week, TrafficDevice::Desktop);

    TrafficVisit::query()->whereKey($visit->id)->delete();

    expect(AccountAttribution::query()->sole()->credited_visit_id)->toBeNull()
        ->and(app(RetentionQuery::class)->groups(retentionWeeks(), $week, TrafficDevice::Desktop))->toBe($before)
        ->and(retentionGroups($before))->toBe(['reddit:organic | -' => ['accounts' => 0, 'weeks' => ['0 0 0 0 0 0', '1 1 0 0 1 0', '0 0 0 0 0 0']]])
        ->and(retentionThroughVisits()->groups(retentionWeeks(), $week, TrafficDevice::Desktop))->toBe([])
        ->and(app(RetentionQuery::class)->returning($week, TrafficDevice::Desktop))->toBe(1)
        ->and(retentionThroughVisits()->returning($week, TrafficDevice::Desktop))->toBe(0);
});
