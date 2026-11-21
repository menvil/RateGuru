<?php

namespace App\Actions\Analytics;

use App\Models\AnalyticsDirtyDay;
use App\Models\AnalyticsRollupState;
use App\Queries\Analytics\AnalyticsRollupQuery;
use App\Queries\Analytics\AnalyticsVisitsQuery;
use App\Support\Analytics\AnalyticsRollupWindow;
use Carbon\CarbonImmutable;

/**
 * Keeps the rows Growth → Analytics reads (analytics_visits) up to date with
 * the visits, for analytics:refresh-visits (docs/architecture/analytics.md,
 * "What a tab reads"), in this order while the time given lasts:
 *
 *  1. the days whose visits still change by themselves (AnalyticsRollupWindow):
 *     today's UTC day and later every time, the earlier ones every fifth
 *     minute — then the rows count as brought up to date. Analytics keeps
 *     what a tab read for ten minutes anyway, and a day read costs seconds;
 *  2. the older days marked as changed (MarkAnalyticsDayAction), the earliest
 *     first, each unmarked once read unless it changed again meanwhile;
 *  3. until every day has been, the days not filled yet, the earliest first,
 *     from the first visit's — and once they reach the days of 1, the rows
 *     count as filled, and Analytics reads them;
 *  4. when asked, the days given back from today again, a check that nothing
 *     was missed.
 */
final class RefreshAnalyticsVisitsAction
{
    private const int DIRTY_DAYS = 50;

    /** Every so many minutes the whole of the days that change by themselves is read, not only today's on. */
    private const int WHOLE_WINDOW_MINUTES = 5;

    public function __construct(
        private readonly RefreshAnalyticsDayAction $day,
        private readonly AnalyticsRollupQuery $rollup,
        private readonly AnalyticsVisitsQuery $visits,
    ) {}

    /**
     * @param  float  $deadline  the moment to stop by (microtime)
     * @param  int  $recheckDays  how many days back from today to read again, after the rest; none when 0
     * @return array{days: int, written: int, filled: bool}
     */
    public function handle(float $deadline, int $recheckDays = 0, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $window = AnalyticsRollupWindow::days($now);
        $today = $now->toDateString();
        $due = $recheckDays > 0 || $now->minute % self::WHOLE_WINDOW_MINUTES === 0
            ? $window
            : array_values(array_filter($window, fn (string $day): bool => $day >= $today));
        $days = 0;
        $written = 0;

        foreach ($due as $day) {
            $written += $this->day->handle($day);
            $days++;
        }

        $state = AnalyticsRollupState::current();
        $state->forceFill(['refreshed_at' => $now])->save();

        foreach ($this->rollup->dirtyDays(self::DIRTY_DAYS) as $day => $token) {
            if (microtime(true) >= $deadline) {
                break;
            }

            $written += $this->day->handle($day);
            $days++;
            AnalyticsDirtyDay::query()->where('day', $day)->where('token', $token)->delete();
        }

        if ($state->filled_at === null) {
            $day = $state->filled_through === null ? $this->visits->firstDay() : CarbonImmutable::parse($state->filled_through, 'UTC')->addDay()->toDateString();

            for (; $day !== null && $day < $window[0]; $day = CarbonImmutable::parse($day, 'UTC')->addDay()->toDateString()) {
                if (microtime(true) >= $deadline) {
                    break;
                }

                $written += $this->day->handle($day);
                $days++;
                $state->forceFill(['filled_through' => $day])->save();
            }

            if ($day === null || $day >= $window[0]) {
                $state->forceFill(['filled_at' => $now])->save();
            }
        }

        for ($back = $recheckDays; $back > 0 && microtime(true) < $deadline; $back--) {
            $day = $now->subDays($back)->toDateString();

            if ($day < $window[0]) {
                $written += $this->day->handle($day);
                $days++;
            }
        }

        return ['days' => $days, 'written' => $written, 'filled' => $state->filled_at !== null];
    }
}
