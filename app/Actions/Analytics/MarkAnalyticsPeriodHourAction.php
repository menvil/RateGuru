<?php

namespace App\Actions\Analytics;

use App\Models\AnalyticsDirtyPeriodHour;
use App\Models\GameAssignment;
use App\Models\GameAttempt;
use App\Support\Analytics\PeriodFunnelHours;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Tells a traffic period's funnel that an assignment of an hour it may have
 * counted changed (docs/architecture/analytics.md, "The hours of a period's
 * assignments"): an attempt of it started, completed, saw a result, left an
 * email or reached the goal, an account credited to one was confirmed or
 * activated, or something of it was deleted. Until analytics:refresh-visits
 * counts the hour again, the funnel reads that hour from the assignments
 * themselves, so it is never behind. An hour still open
 * (PeriodFunnelHours::isOpen()) is read from the assignments anyway and is
 * not counted before every change made while it was open is in: nothing to
 * tell. The token is new on every call, so a change made while the hour is
 * counted leaves it marked; the first time an hour is marked is kept. A
 * caller marks in the transaction of its change, so the two are kept, or
 * lost, together.
 */
final class MarkAnalyticsPeriodHourAction
{
    /** Marks the hour of an assignment made at the moment given; nothing for none. */
    public function handle(?CarbonInterface $assignedAt, ?CarbonImmutable $now = null): void
    {
        if ($assignedAt === null) {
            return;
        }

        $now = ($now ?? CarbonImmutable::now())->utc();
        $hour = PeriodFunnelHours::hourOf($assignedAt);

        if (PeriodFunnelHours::isOpen($hour, $now)) {
            return;
        }

        AnalyticsDirtyPeriodHour::query()->upsert(
            [['assigned_hour' => $hour, 'token' => (string) Str::ulid(), 'marked_at' => $now]],
            ['assigned_hour'],
            ['token'],
        );
    }

    /** Marks the hour of the assignment an attempt was started from; nothing for an attempt started from none. */
    public function ofAttempt(GameAttempt $attempt): void
    {
        if ($attempt->assignment_id === null) {
            return;
        }

        $assignedAt = GameAssignment::query()->whereKey($attempt->assignment_id)->value('assigned_at');
        $this->handle($assignedAt === null ? null : CarbonImmutable::parse((string) $assignedAt, 'UTC'));
    }

    /**
     * Marks the hours of the assignments given, each hour once — before what
     * is deleted of them goes, while the assignments are still there to tell
     * their hours — the earliest first, so two callers marking some of the
     * same hours lock them in one order and never wait on each other.
     *
     * @param  Builder<GameAssignment>  $assignments
     */
    public function ofAssignments(Builder $assignments): void
    {
        $hours = [];

        foreach ($assignments->toBase()->pluck('game_assignments.assigned_at') as $assignedAt) {
            $hour = PeriodFunnelHours::hourOf(CarbonImmutable::parse((string) $assignedAt, 'UTC'));
            $hours[$hour->toDateTimeString()] = $hour;
        }

        ksort($hours);

        foreach ($hours as $hour) {
            $this->handle($hour);
        }
    }
}
