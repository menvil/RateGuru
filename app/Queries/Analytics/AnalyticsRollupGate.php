<?php

namespace App\Queries\Analytics;

use App\Models\AnalyticsCell;
use App\Models\AnalyticsDirtyDay;
use App\Models\AnalyticsRollupState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;

/**
 * Whether Growth → Analytics reads its own rows of the visits
 * (analytics_visits) rather than the visits themselves: only once they were
 * filled as far back as visits go, while they were brought up to date
 * recently (analytics.rollup.stale_minutes) and no day marked as changed has
 * waited longer than that to be read again — a refresh that stopped, or one
 * that keeps failing on a day, puts the tabs back on the visits, slower but
 * never behind — and while the setting allows it (analytics.rollup.read).
 *
 * And whether it reads the cells of each day's people (analytics_cells) where
 * a read fits them: only where they are kept at all — PostgreSQL with the
 * roaringbitmap extension — once they were filled as far back as the rows,
 * while the rows are read, and while the setting allows it
 * (analytics.rollup.cells). Without them every figure is the same, read from
 * the rows.
 *
 * And whether a game's figures read the sums of its days
 * (analytics_game_days): every engine, once they were filled as far back as
 * the rows, while the rows are read, and while the setting allows it
 * (analytics.rollup.game_days). Decided once a request: bound scoped.
 *
 * And how a game's figures take the people of its days from the cells of the
 * games' days (analytics_game_cells): only where the cells of each day's
 * people are read, once the game cells were filled, and while the setting
 * allows it (analytics.rollup.game_cells) — read at every call, with the days
 * changed since they were counted.
 */
final class AnalyticsRollupGate
{
    /** The most hours changed since they were counted that a period's funnel reads beside the sums — a minute's on a busy site; past it, read every assignment. */
    public const int MOST_CHANGED_HOURS = 100;

    /** The most days changed since they were counted that a game's figures read beside the cells of the games' days — more than a refresh that keeps up ever leaves; past it, read the events and attempts. */
    public const int MOST_CHANGED_DAYS = 31;

    private ?bool $reads = null;

    private ?bool $kept = null;

    private ?bool $readsCells = null;

    private ?bool $readsGameDays = null;

    public function reads(): bool
    {
        if ($this->reads !== null) {
            return $this->reads;
        }

        if (! (bool) config('analytics.rollup.read')) {
            return $this->reads = false;
        }

        $state = AnalyticsRollupState::query()->find(AnalyticsRollupState::ID);
        $staleBefore = CarbonImmutable::now('UTC')->subMinutes((int) config('analytics.rollup.stale_minutes'));

        return $this->reads = $state?->filled_at !== null
            && $state->refreshed_at !== null
            && $state->refreshed_at->greaterThanOrEqualTo($staleBefore)
            && ! AnalyticsDirtyDay::query()->where('marked_at', '<', $staleBefore)->exists();
    }

    /** Whether the cells can be kept here at all: PostgreSQL, with the roaringbitmap extension in the database. */
    public function cellsKept(): bool
    {
        if ($this->kept !== null) {
            return $this->kept;
        }

        $connection = (new AnalyticsCell)->getConnection();

        return $this->kept = $connection->getDriverName() === 'pgsql'
            && $connection->table('pg_extension')->where('extname', 'roaringbitmap')->exists();
    }

    public function readsCells(): bool
    {
        if ($this->readsCells !== null) {
            return $this->readsCells;
        }

        return $this->readsCells = (bool) config('analytics.rollup.cells')
            && $this->reads()
            && $this->cellsKept()
            && AnalyticsRollupState::query()->whereKey(AnalyticsRollupState::ID)->whereNotNull('cells_filled_at')->exists();
    }

    public function readsGameDays(): bool
    {
        if ($this->readsGameDays !== null) {
            return $this->readsGameDays;
        }

        return $this->readsGameDays = (bool) config('analytics.rollup.game_days')
            && $this->reads()
            && AnalyticsRollupState::query()->whereKey(AnalyticsRollupState::ID)->whereNotNull('game_days_filled_at')->exists();
    }

    /**
     * How a traffic period's funnel takes the hours of its assignments from
     * their sums (analytics_period_hours), on every engine: the last hour
     * every hour up to which is counted, and the hours counted that changed
     * since (analytics_dirty_period_hours), the earliest first — both in one
     * read, at every call, so each funnel is read with the hours it is summed
     * from. The assignments of the hours since and of those changed are read
     * beside the sums, so it never depends on how recently they were counted.
     * None — read every assignment — while the setting says so
     * (analytics.rollup.period_hours), no hour is counted yet, or more hours
     * changed than MOST_CHANGED_HOURS wait, a refresh that stopped.
     *
     * @return array{through: CarbonImmutable, changed: list<CarbonImmutable>}|null
     */
    public function periodHours(): ?array
    {
        if (! (bool) config('analytics.rollup.period_hours')) {
            return null;
        }

        $rows = AnalyticsRollupState::query()
            ->leftJoin('analytics_dirty_period_hours', 'analytics_dirty_period_hours.assigned_hour', '<=', 'analytics_rollup_states.period_hours_through')
            ->whereKey(AnalyticsRollupState::ID)
            ->orderBy('analytics_dirty_period_hours.assigned_hour')
            ->limit(self::MOST_CHANGED_HOURS + 1)
            ->toBase()
            ->get(['analytics_rollup_states.period_hours_through', 'analytics_dirty_period_hours.assigned_hour']);
        $through = $rows->first()?->period_hours_through;
        $changed = $rows->pluck('assigned_hour')->filter(fn (mixed $hour): bool => $hour !== null)->map(fn (mixed $hour): CarbonImmutable => CarbonImmutable::parse((string) $hour, 'UTC'))->values()->all();

        return $through === null || count($changed) > self::MOST_CHANGED_HOURS ? null : ['through' => CarbonImmutable::parse((string) $through, 'UTC'), 'changed' => $changed];
    }

    /**
     * How a game's figures take the people of its days from the cells of the
     * games' days (analytics_game_cells, docs/architecture/analytics.md, "The
     * people of a game's days"): the last day every day up to which is
     * counted whole, the moment the days after it are counted up to, and the
     * days marked as changed since they were counted (analytics_dirty_game_days)
     * — all in one read, at every call, so each figure is read with the cells
     * it is summed from. None — read the events and attempts — while the cells
     * of each day's people aren't read (readsCells()), the setting says so
     * (analytics.rollup.game_cells), the game cells aren't filled yet, the
     * open days were last counted longer ago than the rows may be behind
     * (analytics.rollup.stale_minutes) — a count that keeps failing, whose
     * figures would read ever more of the events and attempts themselves —
     * or more days changed than MOST_CHANGED_DAYS wait.
     *
     * @return array{through: string, moment: CarbonImmutable, changed: list<string>}|null
     */
    public function gameCells(): ?array
    {
        if (! (bool) config('analytics.rollup.game_cells') || ! $this->readsCells()) {
            return null;
        }

        $rows = AnalyticsRollupState::query()
            // Every day marked, beside the one row of the state.
            ->leftJoin('analytics_dirty_game_days', fn (JoinClause $join): JoinClause => $join->whereNotNull('analytics_dirty_game_days.day'))
            ->whereKey(AnalyticsRollupState::ID)
            ->orderBy('analytics_dirty_game_days.day')
            ->limit(self::MOST_CHANGED_DAYS + 1)
            ->toBase()
            ->get(['analytics_rollup_states.game_cells_through', 'analytics_rollup_states.game_cells_moment', 'analytics_rollup_states.game_cells_filled_at', 'analytics_dirty_game_days.day']);
        $state = $rows->first();
        $changed = $rows->pluck('day')->filter(fn (mixed $day): bool => $day !== null)->map(fn (mixed $day): string => (string) $day)->values()->all();
        $moment = $state?->game_cells_moment === null ? null : CarbonImmutable::parse((string) $state->game_cells_moment, 'UTC');
        $staleBefore = CarbonImmutable::now('UTC')->subMinutes((int) config('analytics.rollup.stale_minutes'));

        if ($state === null || $state->game_cells_filled_at === null || $state->game_cells_through === null || $moment === null || $moment->lessThan($staleBefore) || count($changed) > self::MOST_CHANGED_DAYS) {
            return null;
        }

        return ['through' => (string) $state->game_cells_through, 'moment' => $moment, 'changed' => $changed];
    }
}
