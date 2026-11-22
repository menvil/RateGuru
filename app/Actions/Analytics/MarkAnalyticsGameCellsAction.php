<?php

namespace App\Actions\Analytics;

use App\Enums\GameEventType;
use App\Models\AnalyticsDirtyGameDay;
use App\Models\GameAttempt;
use App\Models\GameEvent;
use App\Queries\Analytics\AnalyticsGameCellsQuery;
use App\Queries\Analytics\AnalyticsRollupGate;
use App\Queries\Games\GameMetricsQuery;
use App\Support\Analytics\GameCellDays;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Tells the people of the games' days (analytics_game_cells,
 * docs/architecture/analytics.md, "The people of a game's days") that a day
 * they may have counted changed: an attempt begun on it completed, its goal
 * reached, taken by an account or an account credited to it confirmed,
 * shared, sent on or signing up begun once the day had closed — each told by
 * the event recorded with it (GameEventRecorder); while the day is open, the
 * moment it happened tells (GameCellDays) — or an attempt, a view or an
 * event of an attempt of it deleted, which no moment tells, whenever. Until
 * analytics:refresh-visits counts the day again, a game's figures read it
 * from the events and attempts themselves, so they are never behind. The
 * token is new on every call, so a change made while the day is counted
 * leaves it marked; the first time a day is marked is kept. A caller marks in
 * the transaction of its change, so the two are kept, or lost, together.
 * Only where the cells are kept (PostgreSQL with the roaringbitmap
 * extension); elsewhere nothing counts the days, and nothing is told.
 */
final class MarkAnalyticsGameCellsAction
{
    public function __construct(private readonly AnalyticsRollupGate $gate) {}

    /**
     * Marks the UTC day of a moment — an attempt's start, a view's — once the
     * day has closed; whatever the day, for a change no moment tells.
     */
    public function handle(?CarbonInterface $of, bool $whenever = false, ?CarbonImmutable $now = null): void
    {
        if ($of === null) {
            return;
        }

        $now = ($now ?? CarbonImmutable::now())->utc();
        $day = GameCellDays::dayOf($of);

        // An open day first, which asks nothing of the database: what playing records now is of one.
        if ((! $whenever && GameCellDays::isOpen($day, $now)) || ! $this->gate->cellsKept()) {
            return;
        }

        AnalyticsDirtyGameDay::query()->upsert(
            [['day' => $day, 'token' => (string) Str::ulid(), 'marked_at' => $now]],
            ['day'],
            ['token'],
        );
    }

    /** Marks the day an attempt began on, when the event just recorded of it tells of a change the cells count (AnalyticsGameCellsQuery::TOLD_BY). */
    public function ofEvent(GameEventType $type, GameAttempt $attempt): void
    {
        if (in_array($type, AnalyticsGameCellsQuery::TOLD_BY, true)) {
            $this->handle($attempt->started_at);
        }
    }

    /**
     * Marks the days of events about to be deleted: of the views among them,
     * and of the attempts whose events among them the cells count people by
     * (GameMetricsQuery::FUNNEL_EVENTS) — before they go, while they are
     * still there to tell their days.
     *
     * @param  Builder<GameEvent>  $events
     */
    public function removingEvents(Builder $events): void
    {
        if (! $this->gate->cellsKept()) {
            return;
        }

        $this->removing([
            ...(clone $events)->where('type', GameEventType::View->value)->toBase()->pluck('occurred_at')->all(),
            ...GameAttempt::query()
                ->whereIn('id', (clone $events)->whereIn('type', array_map(fn (GameEventType $type): string => $type->value, GameMetricsQuery::FUNNEL_EVENTS))->whereNotNull('attempt_id')->select('attempt_id'))
                ->toBase()
                ->pluck('started_at')
                ->all(),
        ]);
    }

    /**
     * Marks the days of what is about to be deleted — attempts, and the
     * attempts of events, by the moment each began, views by the moment each
     * was seen — each day once and the earliest first, so two callers marking
     * some of the same days lock them in one order and never wait on each
     * other.
     *
     * @param  iterable<mixed>  $moments
     */
    public function removing(iterable $moments): void
    {
        $days = [];

        foreach ($moments as $moment) {
            if ($moment !== null) {
                $days[GameCellDays::dayOf($moment instanceof CarbonInterface ? $moment : CarbonImmutable::parse((string) $moment, 'UTC'))] = true;
            }
        }

        ksort($days);

        foreach (array_keys($days) as $day) {
            $this->handle(CarbonImmutable::parse($day, 'UTC'), whenever: true);
        }
    }
}
