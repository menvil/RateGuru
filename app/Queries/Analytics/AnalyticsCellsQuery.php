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
 * every one, their pages run or not, what the figures leave out; each post's
 * people of the day; and the day's accounts by the post their session opened
 * first. PostgreSQL with the roaringbitmap extension only: the action asks
 * the gate first.
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
        'post_viewers' => 'v.opened AND v.post_viewed',
        'post_voters' => 'v.opened AND v.post_voted',
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
        'post_rows' => 'COALESCE(SUM(v.post_rows) FILTER (WHERE v.opened), 0)',
        'post_views' => 'COALESCE(SUM(v.post_views) FILTER (WHERE v.opened), 0)',
        'post_shares' => 'COALESCE(SUM(v.post_shares) FILTER (WHERE v.opened), 0)',
        'attempts' => 'COALESCE(SUM(v.attempts) FILTER (WHERE v.opened), 0)',
        'share_clicks' => 'COALESCE(SUM(v.share_clicks) FILTER (WHERE v.opened), 0)',
        'shared_results' => 'COALESCE(SUM(v.shared_results) FILTER (WHERE v.opened), 0)',
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
        $columns = [$this->level(), '? AS network_day', ...self::keys()];

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
                $this->level(), '? AS network_day', ...self::keys(),
                "TO_CHAR(v.signup_at, 'YYYY-MM-DD') AS signup_day",
                'rb_build_agg(p.pid)::bytea AS accounts',
            ]), [...$this->levelBindings(), $day])
            ->groupByRaw($this->groupingSets(", TO_CHAR(v.signup_at, 'YYYY-MM-DD')"), []);
    }

    /** @return list<string> the columns postCells() selects, in order */
    public static function postCellColumns(): array
    {
        return ['network_day', 'device', 'post_id', 'viewers', 'engaged', 'voters', 'views', 'votes', 'comments'];
    }

    /**
     * Each post's people of the day by device, from the rows of the posts of
     * the day's visits whose page ran (postCellColumns()): those who opened it,
     * of them those who voted on it or commented on it, those who voted on it,
     * and its views, votes and comments — what AnalyticsContentQuery::topPosts()
     * reads of each visit's row of a post.
     *
     * @return Builder<AnalyticsVisit>
     */
    public function postCells(string $day): Builder
    {
        return $this->ofDay($day)
            ->join('traffic_visit_posts as s', 's.visit_id', '=', 'v.id')
            ->where('v.opened', true)
            ->selectRaw(implode(', ', [
                '? AS network_day', 'v.device', 's.post_id',
                'rb_build_agg(p.pid) FILTER (WHERE s.views > 0)::bytea AS viewers',
                'rb_build_agg(p.pid) FILTER (WHERE s.views > 0 AND (s.voted OR s.commented))::bytea AS engaged',
                'rb_build_agg(p.pid) FILTER (WHERE s.voted)::bytea AS voters',
                'COALESCE(SUM(s.views), 0) AS views',
                'COUNT(*) FILTER (WHERE s.voted) AS votes',
                'COUNT(*) FILTER (WHERE s.commented) AS comments',
            ]), [$day])
            ->groupBy('v.device', 's.post_id');
    }

    /** @return list<string> the columns firstPostCells() selects, in order */
    public static function firstPostCellColumns(): array
    {
        return ['network_day', 'device', 'post_id', 'accounts'];
    }

    /**
     * The accounts credited to the day's visits whose page ran, by device and
     * the post their session opened first — no post for those whose session
     * opened none — each set the browsers' numbers (firstPostCellColumns()):
     * what AnalyticsContentQuery::firstPostAccounts() and unattributedAccounts()
     * read of each credited visit's row.
     *
     * @return Builder<AnalyticsVisit>
     */
    public function firstPostCells(string $day): Builder
    {
        return $this->ofDay($day)
            ->where('v.opened', true)
            ->where('v.account', true)
            ->selectRaw('? AS network_day, v.device, v.session_post_id AS post_id, rb_build_agg(p.pid)::bytea AS accounts', [$day])
            ->groupBy('v.device', 'v.session_post_id');
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

    /** @return list<string> every key column of a cell, read from the day's rows, in KEY_COLUMNS' order */
    private static function keys(): array
    {
        return array_map(fn (string $column): string => 'v.'.$column, AnalyticsCellLevel::KEY_COLUMNS);
    }

    /** The level of a cell, by which of its columns it is split by (AnalyticsCellLevel::groupingBits()). */
    private function level(): string
    {
        $cases = array_map(fn (AnalyticsCellLevel $level): string => 'WHEN '.$level->groupingBits().' THEN ?', AnalyticsCellLevel::cases());
        $columns = array_map(fn (string $column): string => 'v.'.$column, AnalyticsCellLevel::GROUPING_COLUMNS);

        return 'CASE GROUPING('.implode(', ', $columns).') '.implode(' ', $cases).' END AS level';
    }

    /** @return list<string> the level of each case of level() */
    private function levelBindings(): array
    {
        return array_map(fn (AnalyticsCellLevel $level): string => $level->value, AnalyticsCellLevel::cases());
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
