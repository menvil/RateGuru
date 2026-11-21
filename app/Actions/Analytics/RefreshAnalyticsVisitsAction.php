<?php

namespace App\Actions\Analytics;

use App\Models\AnalyticsDirtyDay;
use App\Models\AnalyticsRollupState;
use App\Queries\Analytics\AnalyticsRollupGate;
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
 *     first, each unmarked once read unless it changed again meanwhile — then
 *     marked as of now, since what is left is the change made during the read;
 *  3. until every day has been, the days not filled yet, the earliest first,
 *     from the first visit's — and once they reach the days of 1, and every
 *     day of 1 this run skipped has been read too, the rows count as filled,
 *     and Analytics reads them;
 *  4. where the cells of each day's people are kept (analytics_cells), until
 *     every day has them, the days without, the earliest first, from the
 *     first visit's to the last day of 1 — then the cells count as filled,
 *     and Analytics reads them; each day's cells are written again with its
 *     rows from then on (RefreshAnalyticsDayAction);
 *  5. likewise the sums of each day's games' visits (analytics_game_days),
 *     on every engine, until every day has them — then they count as filled,
 *     and a game's figures read them;
 *  6. when asked, the days given back from today again, their cells and
 *     sums too, a check that nothing was missed.
 */
final class RefreshAnalyticsVisitsAction
{
    private const int DIRTY_DAYS = 50;

    public function __construct(
        private readonly RefreshAnalyticsDayAction $day,
        private readonly RefreshAnalyticsCellsAction $cells,
        private readonly RefreshAnalyticsGameDaysAction $gameDays,
        private readonly AnalyticsRollupQuery $rollup,
        private readonly AnalyticsVisitsQuery $visits,
        private readonly AnalyticsRollupGate $gate,
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
        $due = $recheckDays > 0 || $now->minute % AnalyticsRollupWindow::WHOLE_MINUTES === 0
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

            if (AnalyticsDirtyDay::query()->where('day', $day)->where('token', $token)->delete() === 0) {
                AnalyticsDirtyDay::query()->where('day', $day)->update(['marked_at' => $now]);
            }
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
                // The days of 1 this run left for the fifth minute, before
                // Analytics starts reading the rows: otherwise the first
                // minutes would show those days as they were filled, not as
                // they are. A deadline reached first leaves the rows unfilled,
                // for the next run.
                $complete = true;

                foreach (array_diff($window, $due) as $skipped) {
                    if (microtime(true) >= $deadline) {
                        $complete = false;

                        break;
                    }

                    $written += $this->day->handle($skipped);
                    $days++;
                }

                if ($complete) {
                    $state->forceFill(['filled_at' => $now])->save();
                }
            }
        }

        if ($state->filled_at !== null && $state->cells_filled_at === null && $this->gate->cellsKept()) {
            $last = $window[count($window) - 1];
            $day = $state->cells_through === null ? $this->visits->firstDay() : CarbonImmutable::parse($state->cells_through, 'UTC')->addDay()->toDateString();

            for (; $day !== null && $day <= $last; $day = CarbonImmutable::parse($day, 'UTC')->addDay()->toDateString()) {
                if (microtime(true) >= $deadline) {
                    break;
                }

                $this->cells->handle($day);
                $state->forceFill(['cells_through' => $day])->save();
            }

            if ($day === null || $day > $last) {
                $state->forceFill(['cells_filled_at' => $now])->save();
            }
        }

        if ($state->filled_at !== null && $state->game_days_filled_at === null) {
            $last = $window[count($window) - 1];
            $day = $state->game_days_through === null ? $this->visits->firstDay() : CarbonImmutable::parse($state->game_days_through, 'UTC')->addDay()->toDateString();

            for (; $day !== null && $day <= $last; $day = CarbonImmutable::parse($day, 'UTC')->addDay()->toDateString()) {
                if (microtime(true) >= $deadline) {
                    break;
                }

                $this->gameDays->handle($day);
                $state->forceFill(['game_days_through' => $day])->save();
            }

            if ($day === null || $day > $last) {
                $state->forceFill(['game_days_filled_at' => $now])->save();
            }
        }

        for ($back = $recheckDays; $back > 0 && microtime(true) < $deadline; $back--) {
            $day = $now->subDays($back)->toDateString();

            if ($day < $window[0]) {
                $written += $this->day->handle($day, cells: true);
                $days++;
            }
        }

        return ['days' => $days, 'written' => $written, 'filled' => $state->filled_at !== null];
    }
}
