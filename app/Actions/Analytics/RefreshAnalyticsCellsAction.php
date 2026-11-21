<?php

namespace App\Actions\Analytics;

use App\Models\AnalyticsCell;
use App\Models\AnalyticsCellAccount;
use App\Models\AnalyticsPid;
use App\Queries\Analytics\AnalyticsCellsQuery;
use App\Queries\Analytics\AnalyticsRollupGate;
use Illuminate\Support\Facades\DB;

/**
 * Writes the cells of one network day's people again (analytics_cells,
 * docs/architecture/analytics.md, "The cells of each day's people"), from the
 * day's rows of analytics_visits, in one transaction: each browser whose page
 * ran that day and has no number yet is given the next one (analytics_pids);
 * the day's cells and accounts are deleted and read again at every level
 * (AnalyticsCellsQuery). Only on PostgreSQL with the roaringbitmap
 * extension; elsewhere it does nothing. Called after the day's rows are
 * written (RefreshAnalyticsDayAction).
 */
final class RefreshAnalyticsCellsAction
{
    public function __construct(private readonly AnalyticsRollupGate $gate, private readonly AnalyticsCellsQuery $cells) {}

    /** @return bool whether the day's cells were written: false where they are not kept */
    public function handle(string $day): bool
    {
        if (! $this->gate->cellsKept()) {
            return false;
        }

        DB::transaction(function () use ($day): void {
            AnalyticsPid::query()->insertUsing(['visitor_key', 'pid'], $this->cells->freshNumbers($day));

            AnalyticsCell::query()->where('network_day', $day)->delete();
            AnalyticsCellAccount::query()->where('network_day', $day)->delete();

            AnalyticsCell::query()->insertUsing(AnalyticsCellsQuery::cellColumns(), $this->cells->cells($day));
            AnalyticsCellAccount::query()->insertUsing(AnalyticsCellsQuery::accountColumns(), $this->cells->accounts($day));
        });

        return true;
    }
}
