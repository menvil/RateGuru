<?php

namespace App\Queries\Analytics;

use App\Contracts\Persistence\RawSqlPersistenceBoundary;
use App\Enums\AnalyticsDimension;
use App\Enums\TrafficDevice;
use App\Enums\UserRole;
use App\Models\AccountAttribution;
use App\Models\UserActivityDay;
use App\Support\Analytics\AnalyticsPeriod;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Analytics → Retention, read from the accounts themselves rather than from
 * the visits: the confirmed new accounts of members — never a Persona or a
 * member of staff — by the moment they signed up (`account_attributions`),
 * and the days each came back and did something (`user_activity_days`, whose
 * day of the account's life was worked out in PHP as it was written). An
 * account's source is the key of the visit it is credited to — the key of
 * the visit its session began with, copied onto every visit of the session —
 * its device that visit's, and its game the game of the attempt it is
 * credited to. Every week and the period are bound boundaries of moments,
 * never arithmetic on dates, and every column of the visit comes from
 * AnalyticsDimension.
 *
 *  - groups()      per source and game, the accounts of each week given and
 *                  how many of them came back on day 1, day 7, day 30 and any
 *                  of days 1–7, those of each week inside the period, and the
 *                  accounts of the period — conditional sums over one read of
 *                  the accounts, never a row per account;
 *  - returning()   the members who came back and did something on a day of
 *                  the period;
 *  - firstSignup() the moment the first account was recorded.
 *
 * Read-only.
 */
final class RetentionQuery implements RawSqlPersistenceBoundary
{
    /** @var array<string, array{0: int, 1: int}> the returns looked for, by name: the first and the last day of the account's life each may fall on */
    public const array RETURNS = ['d1' => [1, 1], 'd7' => [7, 7], 'd30' => [30, 30], 'by7' => [1, 7]];

    private const string MOMENT = 'Y-m-d H:i:s';

    public function __construct(private readonly AnalyticsRollupGate $gate) {}

    /** The moment the first account signed up, whether confirmed since or not; none before the first. */
    public function firstSignup(): ?CarbonImmutable
    {
        $first = AccountAttribution::query()->min('signed_up_at');

        return is_string($first) && $first !== '' ? CarbonImmutable::parse($first, 'UTC') : null;
    }

    /**
     * The confirmed accounts of the weeks given and of the period, on the
     * device given, per source key (none for an account credited to no
     * visit) and game (none for one credited to no attempt): each week's
     * accounts, how many came back on each return of RETURNS, and how many
     * signed up inside the period, then the period's accounts. A group with
     * no account in either is left out.
     *
     * @param  list<CarbonImmutable>  $weeks  the first moment of each week, oldest first; each lasts seven days
     * @return list<array{source: string|null, game: int|null, accounts: int, weeks: list<array{users: int, d1: int, d7: int, d30: int, by7: int, inPeriod: int}>}>
     */
    public function groups(array $weeks, AnalyticsPeriod $period, ?TrafficDevice $device): array
    {
        if ($weeks === []) {
            return [];
        }

        $periodFrom = $period->start();
        $periodTo = $period->end();
        $columns = [];
        $bindings = [];
        $count = function (string $alias, CarbonImmutable $from, CarbonImmutable $to, ?string $return = null) use (&$columns, &$bindings): void {
            $columns[] = 'SUM(CASE WHEN signups.signed_up_at >= ? AND signups.signed_up_at < ?'.($return === null ? '' : ' AND signups.'.$return.' IS NOT NULL').' THEN 1 ELSE 0 END) AS '.$alias;
            array_push($bindings, $from->format(self::MOMENT), $to->format(self::MOMENT));
        };

        foreach ($weeks as $index => $start) {
            $end = $start->addDays(7);
            $count('w'.$index.'_users', $start, $end);

            foreach (array_keys(self::RETURNS) as $return) {
                $count('w'.$index.'_'.$return, $start, $end, $return);
            }

            // The part of the week inside the period, when there is one.
            $from = $start->max($periodFrom);
            $to = $end->min($periodTo);

            if ($from->lessThan($to)) {
                $count('w'.$index.'_in', $from, $to);
            }
        }

        $count('accounts', $periodFrom, $periodTo);

        $rows = AccountAttribution::query()
            ->fromSub($this->signups($weeks[0], end($weeks)->addDays(7), $periodFrom, $periodTo, $device), 'signups')
            ->groupBy('signups.source_key', 'signups.game_id')
            ->select('signups.source_key', 'signups.game_id')
            ->selectRaw(implode(', ', $columns), $bindings)
            ->toBase()
            ->get();
        $groups = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $counted = [];

            foreach (array_keys($weeks) as $index) {
                $counted[] = [
                    'users' => (int) ($row['w'.$index.'_users'] ?? 0),
                    'd1' => (int) ($row['w'.$index.'_d1'] ?? 0),
                    'd7' => (int) ($row['w'.$index.'_d7'] ?? 0),
                    'd30' => (int) ($row['w'.$index.'_d30'] ?? 0),
                    'by7' => (int) ($row['w'.$index.'_by7'] ?? 0),
                    'inPeriod' => (int) ($row['w'.$index.'_in'] ?? 0),
                ];
            }

            $groups[] = [
                'source' => is_string($row['source_key']) && $row['source_key'] !== '' ? $row['source_key'] : null,
                'game' => $row['game_id'] === null ? null : (int) $row['game_id'],
                'accounts' => (int) ($row['accounts'] ?? 0),
                'weeks' => $counted,
            ];
        }

        return $groups;
    }

    /**
     * The members who came back and did something on a day of the period —
     * any day of their account's life after the one they signed up on —
     * each once: confirmed new accounts, and members whose account came
     * before accounts were recorded. On a device, only accounts whose
     * credited visit was on it.
     */
    public function returning(AnalyticsPeriod $period, ?TrafficDevice $device): int
    {
        return UserActivityDay::query()
            ->from('user_activity_days as days')
            ->join('users', 'users.id', '=', 'days.user_id')
            ->leftJoin('account_attributions as accounts', 'accounts.user_id', '=', 'days.user_id')
            ->when($device !== null, fn (Builder $query) => $query
                ->join('traffic_visits as credited', 'credited.id', '=', 'accounts.credited_visit_id')
                ->where('credited.'.AnalyticsDimension::Device->columns()[0], $device?->value))
            ->where('users.role', UserRole::User->value)
            ->whereBetween('days.day', [$period->fromDay, $period->toDay])
            ->where('days.day_number', '>=', 1)
            ->where(fn (Builder $query) => $query->whereNull('accounts.id')->orWhereNotNull('accounts.confirmed_at'))
            ->distinct()
            ->count('days.user_id');
    }

    /**
     * One row per confirmed account of a member that signed up in the
     * cohorts' weeks or in the period — with a device given, only those
     * whose credited visit was on it: the moment, the source key of its
     * credited visit, the game of its credited attempt, and — for each
     * return of RETURNS — whether it came back on a day in its range, as the
     * id of a member who did or none.
     *
     * @return Builder<AccountAttribution>
     */
    private function signups(CarbonImmutable $weeksFrom, CarbonImmutable $weeksTo, CarbonImmutable $periodFrom, CarbonImmutable $periodTo, ?TrafficDevice $device): Builder
    {
        $source = AnalyticsDimension::Source->columns()[0];
        $game = AnalyticsDimension::Game->columns()[0];
        $between = fn (CarbonImmutable $from, CarbonImmutable $to): Closure => fn (Builder $query) => $query
            ->where('accounts.signed_up_at', '>=', $from->format(self::MOMENT))
            ->where('accounts.signed_up_at', '<', $to->format(self::MOMENT));

        $query = AccountAttribution::query()
            ->from('account_attributions as accounts')
            ->join('users', 'users.id', '=', 'accounts.user_id')
            // Analytics' own row of the visit, narrower, once filled and up to date: the same source and device.
            ->leftJoin(($this->gate->reads() ? 'analytics_visits' : 'traffic_visits').' as credited', 'credited.id', '=', 'accounts.credited_visit_id')
            ->leftJoin('game_attempts as attempts', 'attempts.id', '=', 'accounts.credited_attempt_id')
            ->where('users.role', UserRole::User->value)
            ->whereNotNull('accounts.confirmed_at')
            ->where(fn (Builder $query) => $query->where($between($weeksFrom, $weeksTo))->orWhere($between($periodFrom, $periodTo)))
            ->when($device !== null, fn (Builder $query) => $query->where('credited.'.AnalyticsDimension::Device->columns()[0], $device?->value))
            ->select('accounts.signed_up_at', 'credited.'.$source.' as source_key', 'attempts.'.$game.' as game_id');

        foreach (self::RETURNS as $return => [$first, $last]) {
            $query->selectSub(
                UserActivityDay::query()
                    ->select('user_activity_days.user_id')
                    ->whereColumn('user_activity_days.user_id', 'accounts.user_id')
                    ->whereBetween('user_activity_days.day_number', [$first, $last])
                    ->limit(1),
                $return,
            );
        }

        return $query;
    }
}
