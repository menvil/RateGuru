<?php

namespace App\Actions\Analytics;

use App\Models\AnalyticsGameDay;
use App\Queries\Analytics\AnalyticsGameDaysQuery;
use Illuminate\Support\Facades\DB;

/**
 * Writes the sums of one network day's games' visits again
 * (analytics_game_days, docs/architecture/analytics.md, "The sums of a
 * game's days"), from the day's rows of analytics_visits, in one
 * transaction: the day's sums deleted, then read again for each UTC day its
 * visits arrived on — a network day in another time zone runs into the UTC
 * days on either side — by country, and those summed without it. Every
 * engine. Called after the day's rows are written (RefreshAnalyticsDayAction).
 */
final class RefreshAnalyticsGameDaysAction
{
    public function __construct(private readonly AnalyticsGameDaysQuery $days) {}

    public function handle(string $networkDay): void
    {
        DB::transaction(function () use ($networkDay): void {
            AnalyticsGameDay::query()->where('network_day', $networkDay)->delete();
            [$first, $last] = $this->days->arrivals($networkDay);

            if ($first === null || $last === null) {
                return;
            }

            for ($day = $first->startOfDay(); $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
                AnalyticsGameDay::query()->insertUsing(AnalyticsGameDaysQuery::columns(), $this->days->byCountry($networkDay, $day));
            }

            AnalyticsGameDay::query()->insertUsing(AnalyticsGameDaysQuery::everyCountryColumns(), $this->days->everyCountry($networkDay));
        });
    }
}
