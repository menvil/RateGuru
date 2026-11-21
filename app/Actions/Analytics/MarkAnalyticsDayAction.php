<?php

namespace App\Actions\Analytics;

use App\Models\AnalyticsDirtyDay;
use App\Support\Analytics\AnalyticsRollupWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Tells Growth → Analytics a visit of an older network day changed — a late
 * confirmation, spend shared out again, a result shared long after — so
 * analytics:refresh-visits reads that day again. A day it reads every
 * minute anyway (AnalyticsRollupWindow) writes nothing. The token is new on
 * every call: a change that comes while the day is being read leaves it
 * marked for the next run.
 */
final class MarkAnalyticsDayAction
{
    public function handle(?string $day, ?CarbonImmutable $now = null): void
    {
        if ($day === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $day = substr($day, 0, 10)) !== 1) {
            return;
        }

        if (AnalyticsRollupWindow::covers($day, $now ?? CarbonImmutable::now())) {
            return;
        }

        AnalyticsDirtyDay::query()->upsert([['day' => $day, 'token' => (string) Str::ulid()]], ['day'], ['token']);
    }
}
