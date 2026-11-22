<?php

namespace App\Actions\Analytics;

use App\Models\AnalyticsDirtyDay;
use App\Support\Analytics\AnalyticsRollupWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Tells Growth → Analytics a visit of an older network day changed — a late
 * confirmation, spend shared out again, a result shared long after — so
 * analytics:refresh-visits reads that day again. A day it reads anyway
 * before the change could be missed — among the days of AnalyticsRollupWindow
 * now and still when they have all been read twice more, so one whole read
 * missed leaves the next — writes nothing. The token is
 * new on every call: a change that comes while the day is being read leaves
 * it marked for the next run. The first time a day is marked is kept
 * (marked_at): a day left unread too long puts Analytics back on the visits
 * (AnalyticsRollupGate). A caller marks once its change is written — in the
 * change's transaction where it has one — never before it: a run reading the
 * day between the two would take the mark away and miss the change.
 */
final class MarkAnalyticsDayAction
{
    public function handle(?string $day, ?CarbonImmutable $now = null): void
    {
        if ($day === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $day = substr($day, 0, 10)) !== 1) {
            return;
        }

        $now ??= CarbonImmutable::now();

        if (AnalyticsRollupWindow::readsChangeMadeAt($day, $now)) {
            return;
        }

        AnalyticsDirtyDay::query()->upsert([['day' => $day, 'token' => (string) Str::ulid(), 'marked_at' => $now->utc()]], ['day'], ['token']);
    }
}
