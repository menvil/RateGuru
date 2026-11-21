<?php

namespace App\Actions\Analytics;

use App\Contracts\Persistence\RawSqlPersistenceBoundary;
use App\Models\AnalyticsCell;
use App\Models\AnalyticsCellAccount;
use App\Models\AnalyticsPid;
use App\Models\AnalyticsVisit;
use App\Queries\Analytics\AnalyticsRollupGate;
use App\Support\Analytics\AnalyticsCellLevel;
use App\Support\Traffic\Reports\TrafficReportFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Writes the cells of one network day's people again (analytics_cells,
 * docs/architecture/analytics.md, "What a tab reads"), from the day's rows of
 * analytics_visits, in one transaction: each browser whose page ran that day
 * and has no number yet is given the next one (analytics_pids); the day's
 * cells and accounts are deleted and read again at every level
 * (AnalyticsCellLevel), in one pass over the day's rows apiece — GROUPING
 * SETS, each step's people a roaring bitmap built of their numbers, only the
 * visits whose page ran, as on the tabs. Only on PostgreSQL with the
 * roaringbitmap extension; elsewhere it does nothing. Called after the day's
 * rows are written (RefreshAnalyticsDayAction).
 */
final class RefreshAnalyticsCellsAction implements RawSqlPersistenceBoundary
{
    /** @var array<string, string> each step's people, as the test of a visit whose page ran */
    private const array PEOPLE = [
        'visitors' => 'v.opened',
        'new_visitors' => 'v.opened AND NOT v.known',
        'starters' => 'v.opened AND v.started',
        'completers' => 'v.opened AND v.completed',
        'goal' => 'v.opened AND v.goal',
        'signup_form' => 'v.opened AND v.signup_form',
        'signups' => 'v.opened AND v.signed_up',
        'accounts' => 'v.opened AND v.account',
        'activated' => 'v.opened AND v.activated',
        'voters' => 'v.opened AND v.votes > 0',
        'commenters' => 'v.opened AND v.comments > 0',
    ];

    /** @var array<string, string> each sum, over the visits whose page ran */
    private const array SUMS = [
        'visits' => 'COUNT(*) FILTER (WHERE v.opened)',
        'tagged' => 'COUNT(*) FILTER (WHERE v.opened AND v.tagged)',
        'votes' => 'COALESCE(SUM(v.votes) FILTER (WHERE v.opened), 0)',
        'comments' => 'COALESCE(SUM(v.comments) FILTER (WHERE v.opened), 0)',
        'posts' => 'COALESCE(SUM(v.posts_created) FILTER (WHERE v.opened), 0)',
        'spend' => 'COALESCE(SUM(v.spend) FILTER (WHERE v.opened), 0)',
        'spend_estimated' => 'COALESCE(SUM(v.spend) FILTER (WHERE v.opened AND v.spend_estimated), 0)',
    ];

    public function __construct(private readonly AnalyticsRollupGate $gate) {}

    /** @return bool whether the day's cells were written: false where they are not kept */
    public function handle(string $day): bool
    {
        if (! $this->gate->cellsKept()) {
            return false;
        }

        DB::transaction(function () use ($day): void {
            $this->number($day);

            AnalyticsCell::query()->where('network_day', $day)->delete();
            AnalyticsCellAccount::query()->where('network_day', $day)->delete();

            AnalyticsCell::query()->insertUsing(
                ['level', 'network_day', ...AnalyticsCellLevel::KEY_COLUMNS, ...array_keys(self::PEOPLE), ...array_keys(self::SUMS)],
                $this->cells($day),
            );

            AnalyticsCellAccount::query()->insertUsing(
                ['level', 'network_day', ...AnalyticsCellLevel::KEY_COLUMNS, 'signup_day', 'accounts'],
                $this->accounts($day),
            );
        });

        return true;
    }

    /**
     * The next numbers, in the order of the browsers, to those whose page ran
     * on the day and have none: one writer at a time (the refresh's lock), so
     * the highest number given is the one to count on from.
     */
    private function number(string $day): void
    {
        $fresh = AnalyticsVisit::query()
            ->from('analytics_visits as v')
            ->where('v.network_day', $day)
            ->where('v.opened', true)
            ->whereNotExists(fn ($numbered) => $numbered->from('analytics_pids as p')->whereColumn('p.visitor_key', 'v.visitor_key'))
            ->distinct()
            ->select('v.visitor_key');

        AnalyticsPid::query()->insertUsing(
            ['visitor_key', 'pid'],
            AnalyticsVisit::query()
                ->fromSub($fresh, 'fresh')
                ->selectRaw('fresh.visitor_key, ? + ROW_NUMBER() OVER (ORDER BY fresh.visitor_key)', [(int) AnalyticsPid::query()->max('pid')]),
        );
    }

    /** @return Builder<AnalyticsVisit> the day's cells at every level, one row a cell with any visit whose page ran */
    private function cells(string $day): Builder
    {
        $columns = [$this->level(), '? AS network_day', 'v.device', 'v.source_key', 'v.campaign_key', 'v.country'];

        foreach (self::PEOPLE as $people => $test) {
            $columns[] = 'rb_build_agg(p.pid) FILTER (WHERE '.$test.')::bytea AS '.$people;
        }

        foreach (self::SUMS as $sum => $expression) {
            $columns[] = $expression.' AS '.$sum;
        }

        return $this->ofDay($day)
            ->selectRaw(implode(', ', $columns), [...$this->levelBindings(), $day])
            ->groupByRaw($this->groupingSets(''), [])
            ->havingRaw('COUNT(*) FILTER (WHERE v.opened) > ?', [0]);
    }

    /** @return Builder<AnalyticsVisit> the day's accounts at every level, by the UTC day each signed up on */
    private function accounts(string $day): Builder
    {
        return $this->ofDay($day)
            ->where('v.opened', true)
            ->where('v.account', true)
            ->whereNotNull('v.signup_at')
            ->selectRaw(implode(', ', [
                $this->level(), '? AS network_day', 'v.device', 'v.source_key', 'v.campaign_key', 'v.country',
                "TO_CHAR(v.signup_at, 'YYYY-MM-DD') AS signup_day",
                'rb_build_agg(p.pid)::bytea AS accounts',
            ]), [...$this->levelBindings(), $day])
            ->groupByRaw($this->groupingSets(", TO_CHAR(v.signup_at, 'YYYY-MM-DD')"), []);
    }

    /** @return Builder<AnalyticsVisit> the day's rows with each browser's number, through the arrivals as every traffic report reads a day */
    private function ofDay(string $day): Builder
    {
        $window = new TrafficReportFilter($day, $day);

        return AnalyticsVisit::query()
            ->from('analytics_visits as v')
            ->leftJoin('analytics_pids as p', 'p.visitor_key', '=', 'v.visitor_key')
            ->where('v.network_day', $day)
            ->where('v.arrived_at', '>=', $window->arrivedFrom())
            ->where('v.arrived_at', '<', $window->arrivedBefore());
    }

    /** The level of a cell, by which of its columns it is split by (AnalyticsCellLevel::ofGrouping()). */
    private function level(): string
    {
        $cases = [];

        foreach ([0b111, 0b011, 0b001, 0b110] as $bits) {
            $cases[] = 'WHEN '.$bits.' THEN ?';
        }

        return 'CASE GROUPING(v.source_key, v.campaign_key, v.country) '.implode(' ', $cases).' END AS level';
    }

    /** @return list<string> the level of each case of level() */
    private function levelBindings(): array
    {
        return array_map(fn (int $bits): string => AnalyticsCellLevel::ofGrouping($bits)->value, [0b111, 0b011, 0b001, 0b110]);
    }

    /** Every level's columns, and whatever else each set groups by. */
    private function groupingSets(string $also): string
    {
        $sets = array_map(
            fn (AnalyticsCellLevel $level): string => '('.implode(', ', array_map(fn (string $column): string => 'v.'.$column, $level->columns())).$also.')',
            AnalyticsCellLevel::cases(),
        );

        return 'GROUPING SETS ('.implode(', ', $sets).')';
    }
}
