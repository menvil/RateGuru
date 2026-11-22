<?php

namespace App\Queries\Analytics;

use App\Models\AnalyticsCell;
use App\Models\AnalyticsDirtyDay;
use App\Models\AnalyticsRollupState;
use Carbon\CarbonImmutable;

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
 */
final class AnalyticsRollupGate
{
    /** The most hours changed since they were counted that a period's funnel reads beside the sums — a minute's on a busy site; past it, read every assignment. */
    public const int MOST_CHANGED_HOURS = 100;

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
}
