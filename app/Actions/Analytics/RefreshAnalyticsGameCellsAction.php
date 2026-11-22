<?php

namespace App\Actions\Analytics;

use App\Models\AnalyticsDirtyGameDay;
use App\Models\AnalyticsGameCell;
use App\Models\AnalyticsGamePid;
use App\Models\AnalyticsRollupState;
use App\Queries\Analytics\AnalyticsGameCellsQuery;
use App\Queries\Analytics\AnalyticsRollupGate;
use App\Support\Analytics\GameCellDays;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Counts the people of the games' days (analytics_game_cells,
 * docs/architecture/analytics.md, "The people of a game's days"), for
 * analytics:refresh-visits, in this order while the time given lasts:
 *
 *  1. the days counted whole that were marked as changed
 *     (MarkAnalyticsGameCellsAction), the earliest first, each unmarked once
 *     counted unless it changed again meanwhile;
 *  2. the days that can be counted whole since the last one was
 *     (GameCellDays::lastClosable()), the earliest first, from the first
 *     view's or attempt's — on deploy, every day of what playing recorded
 *     before — a day nothing was played on passed over, each then the last
 *     day counted whole (game_cells_through);
 *  3. once those are all counted, the days after them — today's, and
 *     yesterday's for its first hour — up to a moment
 *     (GameCellDays::momentOf()), every GENERATION_MINUTES, at once when one
 *     of them was marked: a new count beside the ones before it, which are
 *     dropped once KEEP_MINUTES older than it, never the one just before it
 *     — a read that learned the earlier moment still finds its cells, however
 *     long the refresh had stopped. Then the cells count as filled, and a
 *     game's figures read them;
 *  4. when asked, the days of the last days given again, a check that
 *     nothing was missed.
 *
 * Each day is counted in one transaction, under a lock on the state's row —
 * the one the purge of the numbers takes too, so a number is never let go
 * while a count is giving it to a cell: the guests' browsers seen with no
 * number yet are given one, then the cells are read and inserted, so counting
 * never holds a lock on what playing writes. Only where the cells are kept
 * (PostgreSQL with the roaringbitmap extension); elsewhere it does nothing.
 */
final class RefreshAnalyticsGameCellsAction
{
    private const int MARKED_DAYS = 31;

    public function __construct(
        private readonly AnalyticsRollupGate $gate,
        private readonly AnalyticsGameCellsQuery $cells,
    ) {}

    /**
     * @param  float  $deadline  the moment to stop by (microtime)
     * @param  int  $recheckDays  how many days back from today to count again, after the rest; none when 0
     * @return int how many days were counted, whole or up to a moment
     */
    public function handle(float $deadline, int $recheckDays = 0, ?CarbonImmutable $now = null): int
    {
        if (! $this->gate->cellsKept()) {
            return 0;
        }

        $now = ($now ?? CarbonImmutable::now())->utc()->startOfSecond();
        $state = AnalyticsRollupState::current();
        $through = $state->game_cells_through;
        $counted = 0;

        if ($through !== null) {
            $marks = AnalyticsDirtyGameDay::query()->where('day', '<=', $through)->orderBy('day')->limit(self::MARKED_DAYS)->get();

            foreach ($marks as $mark) {
                if (microtime(true) >= $deadline) {
                    break;
                }

                $this->count($mark->day, $mark->token);
                $counted++;
            }
        }

        $last = GameCellDays::lastClosable($now);
        $from = $through === null ? null : GameCellDays::after($through);
        $closed = false;

        while (microtime(true) < $deadline) {
            if ($from !== null && $from > $last) {
                break;
            }

            // Nothing played since: every day up to the last that can be counted whole is, with nothing in it.
            $next = $this->cells->firstDayFrom($from);
            $day = $next === null || $next > $last ? null : $next;

            DB::transaction(function () use ($state, $day, $last): void {
                self::lock();

                if ($day !== null) {
                    $this->write($day);
                }

                $state->forceFill(['game_cells_through' => $day ?? $last])->save();
            });

            $closed = true;

            if ($day === null) {
                break;
            }

            $counted++;
            $from = GameCellDays::after($day);
        }

        $through = $state->game_cells_through;

        if ($through !== null && $through >= $last && microtime(true) < $deadline) {
            $moment = GameCellDays::momentOf($now);
            /** @var array<string, string> $marks */
            $marks = AnalyticsDirtyGameDay::query()->where('day', '>', $through)->pluck('token', 'day')->all();
            $latest = $state->game_cells_moment;

            if ($state->game_cells_filled_at === null || $latest === null || $closed || $marks !== [] || $latest->lessThanOrEqualTo($moment->subMinutes(GameCellDays::GENERATION_MINUTES))) {
                $this->open($state, $through, $moment, $marks, $now);
                $counted++;
            }
        }

        for ($back = $recheckDays; $back > 0 && $through !== null && microtime(true) < $deadline; $back--) {
            $day = $now->subDays($back)->toDateString();

            if ($day <= $through) {
                $this->count($day);
                $counted++;
            }
        }

        return $counted;
    }

    /**
     * Lets go of the guests' numbers no cell holds any more, never the
     * highest (games:purge-expired), under the lock every count takes; how
     * many. A number a cell marked as changed still holds goes once the cell
     * is counted again without it, on a later run.
     */
    public function forgetNumbers(): int
    {
        if (! $this->gate->cellsKept()) {
            return 0;
        }

        return DB::transaction(function (): int {
            self::lock();

            return $this->cells->unheldNumbers()->delete();
        });
    }

    /** Counts a day whole again, and takes its mark away when the token given is still the mark's: one made meanwhile stays. */
    private function count(string $day, ?string $token = null): void
    {
        DB::transaction(function () use ($day, $token): void {
            self::lock();
            $this->write($day);

            if ($token !== null) {
                AnalyticsDirtyGameDay::query()->where('day', $day)->where('token', $token)->delete();
            }
        });
    }

    /** A day's browsers numbered, and its cells written whole again. */
    private function write(string $day): void
    {
        $from = CarbonImmutable::parse($day, 'UTC');

        AnalyticsGamePid::query()->insertUsing(['visitor_hash', 'pid'], $this->cells->freshNumbers($from, $from->addDay()));
        AnalyticsGameCell::query()->where('day', $day)->whereNull('moment')->delete();
        AnalyticsGameCell::query()->insertUsing(AnalyticsGameCellsQuery::columns(), $this->cells->day($day));
    }

    /**
     * The days after the last counted whole, counted up to the moment, in one
     * transaction with the moment itself and the marks of those days taken
     * away: a read learns the moment and finds its cells together. The counts
     * up to a moment KEEP_MINUTES before it go, but never the latest before
     * it — a read may have just learned that one, however long ago the refresh
     * last ran.
     *
     * @param  array<string, string>  $marks  the tokens of the marks of those days, by day
     */
    private function open(AnalyticsRollupState $state, string $through, CarbonImmutable $moment, array $marks, CarbonImmutable $now): void
    {
        DB::transaction(function () use ($state, $through, $moment, $marks, $now): void {
            self::lock();
            $first = GameCellDays::after($through);
            $latest = AnalyticsRollupState::query()->whereKey(AnalyticsRollupState::ID)->value('game_cells_moment');
            $keepFrom = $moment->subMinutes(GameCellDays::KEEP_MINUTES);

            if ($latest !== null && CarbonImmutable::parse((string) $latest, 'UTC')->lessThan($keepFrom)) {
                $keepFrom = CarbonImmutable::parse((string) $latest, 'UTC');
            }

            AnalyticsGamePid::query()->insertUsing(['visitor_hash', 'pid'], $this->cells->freshNumbers(CarbonImmutable::parse($first, 'UTC'), $moment));
            AnalyticsGameCell::query()->whereNotNull('moment')->where(fn (Builder $moments): Builder => $moments->where('moment', '<', $keepFrom)->orWhere('moment', '>=', $moment))->delete();

            for ($day = $first; $day <= GameCellDays::dayOf($moment); $day = GameCellDays::after($day)) {
                AnalyticsGameCell::query()->insertUsing(AnalyticsGameCellsQuery::columns(), $this->cells->day($day, $moment));
            }

            $state->forceFill(['game_cells_moment' => $moment, 'game_cells_filled_at' => $state->game_cells_filled_at ?? $now])->save();

            foreach ($marks as $day => $token) {
                AnalyticsDirtyGameDay::query()->where('day', $day)->where('token', $token)->delete();
            }
        });
    }

    /** The lock every count and the purge of the numbers take, one at a time: the state's row. */
    private static function lock(): void
    {
        AnalyticsRollupState::query()->whereKey(AnalyticsRollupState::ID)->lockForUpdate()->first();
    }
}
