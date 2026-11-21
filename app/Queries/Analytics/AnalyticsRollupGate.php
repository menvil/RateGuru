<?php

namespace App\Queries\Analytics;

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
 * Decided once a request: bound scoped.
 */
final class AnalyticsRollupGate
{
    private ?bool $reads = null;

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
}
