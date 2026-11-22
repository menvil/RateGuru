<?php

namespace App\Actions\Analytics;

use App\Models\AnalyticsDirtyPeriodHour;
use App\Models\AnalyticsPeriodHour;
use App\Models\AnalyticsPeriodHourAccount;
use App\Models\AnalyticsRollupState;
use App\Models\GameAssignment;
use App\Queries\Games\GameMetricsQuery;
use App\Support\Analytics\PeriodFunnelHours;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Counts the hours of assignments a traffic period's funnel is summed from
 * (analytics_period_hours, docs/architecture/analytics.md, "The hours of a
 * period's assignments"), for analytics:refresh-visits, in this order while
 * the time given lasts:
 *
 *  1. the hours already counted that were marked as changed
 *     (MarkAnalyticsPeriodHourAction), the earliest first, each unmarked once
 *     counted unless it changed again meanwhile;
 *  2. the hours that can be counted since the last one was
 *     (PeriodFunnelHours::lastCountable()), the earliest first, from the
 *     first assignment's — an hour no assignment was made in passed over —
 *     each then the last hour counted (period_hours_through), from which on
 *     the funnel reads it from its sums;
 *  3. when asked, the hours of the days given back from now again, a check
 *     that nothing was missed.
 *
 * Each hour is counted in one transaction: its sums written again from its
 * assignments (GameMetricsQuery::periodHour()), read first and then inserted,
 * so counting never holds a lock on what playing writes — under a lock on the
 * state's row taken before the hour is read, the one the people of the games'
 * days are counted under too: a run started by hand beside the scheduler's
 * counts an hour only once the other is done with it, so it neither writes
 * what it read before the other's count over it nor adds its sums to the
 * other's.
 */
final class RefreshAnalyticsPeriodHoursAction
{
    private const int MARKED_HOURS = 100;

    public function __construct(private readonly GameMetricsQuery $metrics) {}

    /**
     * @param  float  $deadline  the moment to stop by (microtime)
     * @param  int  $recheckDays  how many days back from now to count again, after the rest; none when 0
     * @return int how many hours were counted
     */
    public function handle(float $deadline, int $recheckDays = 0, ?CarbonImmutable $now = null): int
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $last = PeriodFunnelHours::lastCountable($now);
        $state = AnalyticsRollupState::current();
        $through = $state->period_hours_through;
        $counted = 0;

        if ($through !== null) {
            $marks = AnalyticsDirtyPeriodHour::query()->where('assigned_hour', '<=', $through)->orderBy('assigned_hour')->limit(self::MARKED_HOURS)->get();

            foreach ($marks as $mark) {
                if (microtime(true) >= $deadline) {
                    break;
                }

                $this->count($mark->assigned_hour, $mark->token);
                $counted++;
            }
        }

        $from = $through?->addHour() ?? self::firstHourFrom(null);

        while (microtime(true) < $deadline) {
            if ($from !== null && $from->greaterThan($last)) {
                break;
            }

            // No assignment made since: every hour up to the last that can be counted is, with nothing in it.
            $next = $from === null ? null : self::firstHourFrom($from);
            $hour = $next === null || $next->greaterThan($last) ? null : $next;

            DB::transaction(function () use ($state, $hour, $last): void {
                if ($hour !== null) {
                    $this->count($hour);
                }

                $state->forceFill(['period_hours_through' => $hour ?? $last])->save();
            });

            if ($hour === null) {
                break;
            }

            $counted++;
            $from = $hour->addHour();
        }

        $through = $state->period_hours_through;

        for ($hour = PeriodFunnelHours::hourOf($now->subDays($recheckDays)); $recheckDays > 0 && $through !== null && $hour->lessThanOrEqualTo($through); $hour = $hour->addHour()) {
            if (microtime(true) >= $deadline) {
                break;
            }

            $this->count($hour);
            $counted++;
        }

        return $counted;
    }

    /**
     * Writes an hour's sums again from its assignments, and takes its mark
     * away when the token given is still the mark's: one made meanwhile stays.
     */
    private function count(CarbonImmutable $hour, ?string $token = null): void
    {
        DB::transaction(function () use ($hour, $token): void {
            AnalyticsRollupState::query()->whereKey(AnalyticsRollupState::ID)->lockForUpdate()->first();
            ['figures' => $figures, 'accounts' => $accounts] = $this->metrics->periodHour($hour);

            AnalyticsPeriodHour::query()->where('assigned_hour', $hour)->delete();
            AnalyticsPeriodHourAccount::query()->where('assigned_hour', $hour)->delete();

            if ($figures !== []) {
                AnalyticsPeriodHour::query()->insert($figures);
            }

            if ($accounts !== []) {
                AnalyticsPeriodHourAccount::query()->insert($accounts);
            }

            if ($token !== null) {
                AnalyticsDirtyPeriodHour::query()->where('assigned_hour', $hour)->where('token', $token)->delete();
            }
        });
    }

    /** The hour of the first assignment made from a moment on — of all, without one; none when there is none. */
    private static function firstHourFrom(?CarbonImmutable $from): ?CarbonImmutable
    {
        $first = GameAssignment::query()->when($from !== null, fn ($query) => $query->where('assigned_at', '>=', $from))->min('assigned_at');

        return $first === null ? null : PeriodFunnelHours::hourOf(CarbonImmutable::parse((string) $first, 'UTC'));
    }
}
