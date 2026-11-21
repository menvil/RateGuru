<?php

namespace App\Queries\Analytics;

use App\Contracts\Persistence\RawSqlPersistenceBoundary;
use App\Models\AnalyticsPid;
use App\Models\AnalyticsVisit;
use App\Support\Analytics\AnalyticsCellLevel;
use App\Support\Traffic\Reports\TrafficReportFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the cells of one network day's people are written from
 * (RefreshAnalyticsCellsAction, docs/architecture/analytics.md, "The cells of
 * each day's people"): the browsers whose page ran that day and have no number
 * yet, each with the next one; and the day's cells and accounts at every level
 * (AnalyticsCellLevel), each in one pass over the day's rows — GROUPING SETS,
 * each step's people a roaring bitmap built of their numbers, only the visits
 * whose page ran, as on the tabs; and beside them what the paid clicks were,
 * every one, their pages run or not, what the figures leave out. PostgreSQL
 * with the roaringbitmap extension only: the action asks the gate first.
 */
final class AnalyticsCellsQuery implements RawSqlPersistenceBoundary
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
        // Every paid click, its page run or not.
        'paid_clicks' => 'COUNT(*) FILTER (WHERE v.source_id IS NOT NULL)',
        'crawlers' => 'COUNT(*) FILTER (WHERE v.source_id IS NOT NULL AND v.crawler)',
        'never_ran' => 'COUNT(*) FILTER (WHERE v.source_id IS NOT NULL AND NOT v.opened AND NOT v.crawler)',
        'paid_spend' => 'COALESCE(SUM(v.spend) FILTER (WHERE v.source_id IS NOT NULL), 0)',
        'paid_spend_estimated' => 'COALESCE(SUM(v.spend) FILTER (WHERE v.source_id IS NOT NULL AND v.spend_estimated), 0)',
    ];

    /** @return list<string> the columns of a cell, in the order cells() selects them */
    public static function cellColumns(): array
    {
        return ['level', 'network_day', ...AnalyticsCellLevel::KEY_COLUMNS, ...array_keys(self::PEOPLE), ...array_keys(self::SUMS)];
    }

    /** @return list<string> the columns of a cell's accounts, in the order accounts() selects them */
    public static function accountColumns(): array
    {
        return ['level', 'network_day', ...AnalyticsCellLevel::KEY_COLUMNS, 'signup_day', 'accounts'];
    }

    /**
     * The browsers whose page ran on the day and have no number yet, in their
     * order, each with the next number: one writer at a time (the refresh's
     * lock), so the highest number given is the one to count on from.
     *
     * @return Builder<AnalyticsVisit> visitor_key, pid
     */
    public function freshNumbers(string $day): Builder
    {
        $fresh = AnalyticsVisit::query()
            ->from('analytics_visits as v')
            ->where('v.network_day', $day)
            ->where('v.opened', true)
            ->whereNotExists(fn ($numbered) => $numbered->from('analytics_pids as p')->whereColumn('p.visitor_key', 'v.visitor_key'))
            ->distinct()
            ->select('v.visitor_key');

        return AnalyticsVisit::query()
            ->fromSub($fresh, 'fresh')
            ->selectRaw('fresh.visitor_key, ? + ROW_NUMBER() OVER (ORDER BY fresh.visitor_key)', [(int) AnalyticsPid::query()->max('pid')]);
    }

    /** @return Builder<AnalyticsVisit> the day's cells at every level, one row a cell with any visit (cellColumns()) */
    public function cells(string $day): Builder
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
            ->groupByRaw($this->groupingSets(''), []);
    }

    /** @return Builder<AnalyticsVisit> the day's accounts at every level, by the UTC day each signed up on (accountColumns()) */
    public function accounts(string $day): Builder
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
