<?php

namespace App\Queries\Analytics;

use App\Contracts\Persistence\RawSqlPersistenceBoundary;
use App\Enums\TrafficDevice;
use App\Models\Post;
use App\Models\TrafficVisit;
use App\Models\TrafficVisitPost;
use App\Support\Analytics\AnalyticsFilter;
use App\Support\Analytics\AnalyticsPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The posts of Analytics → Site engagement, read from the rows each visit
 * keeps of the posts it opened, shared, voted on or commented on
 * (`traffic_visit_posts`, kept 90 days) beside the visits the filter admits:
 * conditional distinct counts of browsers and sums, every value bound and
 * every column of the visit named by AnalyticsDimension, never arithmetic on
 * dates or a branch per engine. A visit counts once its page ran, as every
 * Analytics figure counts it (AnalyticsVisitsQuery).
 *
 *  - figures()       how often the filter's visits opened a post, the
 *                    browsers that opened one, of them those who voted on
 *                    one they opened, and the posts they shared;
 *  - bySource()      the same for each of the sources given;
 *  - topPosts()      the posts opened most in the days and on the device
 *                    given, whatever source or campaign brought the visits;
 *  - firstPostAccounts() the accounts each of the posts given was the first
 *                    post opened in the session of — the visit an account is
 *                    credited to and every visit sharing its entry;
 *  - unattributedAccounts() the accounts whose session opened no post;
 *  - posts()         the posts given, a deleted one too.
 *
 * Read-only.
 */
final class AnalyticsContentQuery implements RawSqlPersistenceBoundary
{
    /** The most posts a list reads. */
    public const int MOST_POSTS = 100;

    /**
     * What the visits the filter admits did with posts: the times they
     * opened one, the browsers that opened at least one, those of them who
     * voted on a post they opened, and the posts shared — each once a visit.
     *
     * @return array{views: int, viewers: int, voters: int, shares: int}
     */
    public function figures(AnalyticsFilter $filter): array
    {
        [$metrics, $bindings] = self::metrics();

        return self::counted((array) $this->rows($filter)->selectRaw($metrics, $bindings)->toBase()->first());
    }

    /**
     * The same, for each of the sources given among the visits the filter
     * admits, by the source's key: a source no one came from is left out.
     *
     * @param  list<string>  $sourceKeys
     * @return array<string, array{views: int, viewers: int, voters: int, shares: int}>
     */
    public function bySource(AnalyticsFilter $filter, array $sourceKeys): array
    {
        if ($sourceKeys === []) {
            return [];
        }

        [$metrics, $bindings] = self::metrics();
        $figures = [];

        foreach ($this->rows($filter)->whereIn('visits.source_key', $sourceKeys)->groupBy('visits.source_key')->select('visits.source_key')->selectRaw($metrics, $bindings)->toBase()->get() as $row) {
            $row = (array) $row;
            $figures[(string) $row['source_key']] = self::counted($row);
        }

        return $figures;
    }

    /**
     * The posts opened most on the visits of the days and the device given —
     * every source and campaign — the most views first, then by id, at most
     * the limit given: each with its views, the browsers that opened it, of
     * them those who voted on it or commented on it, and the visits that
     * voted on it, the browsers among them, and the visits that commented
     * on it. A post only voted on from the feed, never opened, isn't one of them.
     *
     * @return list<array{postId: int, views: int, viewers: int, engaged: int, votes: int, voters: int, comments: int}>
     */
    public function topPosts(AnalyticsPeriod $period, ?TrafficDevice $device, int $limit): array
    {
        return $this->rows(new AnalyticsFilter($period, $device))
            ->groupBy('seen.post_id')
            ->select('seen.post_id')
            ->selectRaw(implode(', ', [
                'SUM(seen.views) AS views',
                'COUNT(DISTINCT CASE WHEN seen.views > ? THEN visits.visitor_hash END) AS viewers',
                'COUNT(DISTINCT CASE WHEN seen.views > ? AND (seen.voted OR seen.commented) THEN visits.visitor_hash END) AS engaged',
                'SUM(CASE WHEN seen.voted THEN 1 ELSE 0 END) AS votes',
                'COUNT(DISTINCT CASE WHEN seen.voted THEN visits.visitor_hash END) AS voters',
                'SUM(CASE WHEN seen.commented THEN 1 ELSE 0 END) AS comments',
            ]), [0, 0])
            ->havingRaw('SUM(seen.views) > ?', [0])
            ->orderByRaw('SUM(seen.views) DESC', [])
            ->orderBy('seen.post_id')
            ->limit(max(1, min($limit, self::MOST_POSTS)))
            ->toBase()
            ->get()
            ->map(function (object $row): array {
                $row = (array) $row;

                return [
                    'postId' => (int) $row['post_id'],
                    'views' => (int) ($row['views'] ?? 0),
                    'viewers' => (int) ($row['viewers'] ?? 0),
                    'engaged' => (int) ($row['engaged'] ?? 0),
                    'votes' => (int) ($row['votes'] ?? 0),
                    'voters' => (int) ($row['voters'] ?? 0),
                    'comments' => (int) ($row['comments'] ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The accounts credited to a visit of the days and the device given
     * whose session — that visit and every other with the same entry — first
     * opened one of the posts given, by the post: different browsers, as an
     * account is counted. The first post is the one the session opened first
     * — an opened row begins when its post was first opened, though a vote
     * made it earlier (RecordVisitPostAction) — the lower id first at the
     * same moment.
     *
     * @param  list<int>  $postIds
     * @return array<int, int>
     */
    public function firstPostAccounts(AnalyticsPeriod $period, ?TrafficDevice $device, array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }

        $earlier = TrafficVisitPost::query()
            ->from('traffic_visit_posts as earlier')
            ->join('traffic_visits as earlier_visit', 'earlier_visit.id', '=', 'earlier.visit_id')
            ->whereColumn('earlier_visit.entry_visit_id', 'credited.entry_visit_id')
            ->where('earlier.views', '>', 0)
            ->where(fn (Builder $before) => $before
                ->whereColumn('earlier.first_at', '<', 'seen.first_at')
                ->orWhere(fn (Builder $same) => $same->whereColumn('earlier.first_at', '=', 'seen.first_at')->whereColumn('earlier.post_id', '<', 'seen.post_id')));

        $accounts = [];

        foreach ($this->credited($period, $device)
            ->join('traffic_visits as session_visit', 'session_visit.entry_visit_id', '=', 'credited.entry_visit_id')
            ->join('traffic_visit_posts as seen', 'seen.visit_id', '=', 'session_visit.id')
            ->where('seen.views', '>', 0)
            ->whereIn('seen.post_id', $postIds)
            ->whereNotExists($earlier)
            ->groupBy('seen.post_id')
            ->select('seen.post_id')
            ->selectRaw('COUNT(DISTINCT credited.visitor_hash) AS accounts', [])
            ->toBase()
            ->get() as $row) {
            $row = (array) $row;
            $accounts[(int) $row['post_id']] = (int) $row['accounts'];
        }

        return $accounts;
    }

    /** The accounts credited to a visit of the days and the device given whose session opened no post at all: different browsers. */
    public function unattributedAccounts(AnalyticsPeriod $period, ?TrafficDevice $device): int
    {
        $opened = TrafficVisitPost::query()
            ->from('traffic_visit_posts as seen')
            ->join('traffic_visits as session_visit', 'session_visit.id', '=', 'seen.visit_id')
            ->whereColumn('session_visit.entry_visit_id', 'credited.entry_visit_id')
            ->where('seen.views', '>', 0);

        $row = (array) $this->credited($period, $device)
            ->whereNotExists($opened)
            ->selectRaw('COUNT(DISTINCT credited.visitor_hash) AS accounts', [])
            ->toBase()
            ->first();

        return (int) ($row['accounts'] ?? 0);
    }

    /**
     * The posts given, by id, a deleted one too: their titles, and whether
     * each can be opened on the site.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Post>
     */
    public function posts(array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        return Post::withTrashed()->whereKey($ids)->get(['id', 'title', 'status', 'deleted_at'])->keyBy('id');
    }

    /**
     * The rows of the posts of the visits the filter admits, each beside its
     * visit: of the period's network days, read through a wider span of
     * arrivals so the arrival index narrows the scan first.
     *
     * @return Builder<TrafficVisitPost>
     */
    private function rows(AnalyticsFilter $filter): Builder
    {
        $query = TrafficVisitPost::query()
            ->from('traffic_visit_posts as seen')
            ->join('traffic_visits as visits', 'visits.id', '=', 'seen.visit_id')
            ->whereBetween('visits.network_day', [$filter->fromDay(), $filter->toDay()])
            ->where('visits.arrived_at', '>=', $filter->arrivedFrom())
            ->where('visits.arrived_at', '<', $filter->arrivedBefore())
            ->whereNotNull('visits.opened_at');

        foreach ($filter->conditions() as [$column, $value]) {
            $value === null ? $query->whereNull('visits.'.$column) : $query->where('visits.'.$column, $value);
        }

        return $query;
    }

    /**
     * The visits of the days and the device given credited with a new
     * account, their pages run, as `credited`.
     *
     * @return Builder<TrafficVisit>
     */
    private function credited(AnalyticsPeriod $period, ?TrafficDevice $device): Builder
    {
        $filter = new AnalyticsFilter($period, $device);
        $query = TrafficVisit::query()
            ->from('traffic_visits as credited')
            ->whereBetween('credited.network_day', [$filter->fromDay(), $filter->toDay()])
            ->where('credited.arrived_at', '>=', $filter->arrivedFrom())
            ->where('credited.arrived_at', '<', $filter->arrivedBefore())
            ->whereNotNull('credited.opened_at')
            ->whereNotNull('credited.account_at');

        foreach ($filter->conditions() as [$column, $value]) {
            $value === null ? $query->whereNull('credited.'.$column) : $query->where('credited.'.$column, $value);
        }

        return $query;
    }

    /**
     * The figures of a set of post rows, as SQL: the views, the browsers with
     * one, those of them who voted on a post they opened, and the shares.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private static function metrics(): array
    {
        return [implode(', ', [
            'SUM(seen.views) AS views',
            'COUNT(DISTINCT CASE WHEN seen.views > ? THEN visits.visitor_hash END) AS viewers',
            'COUNT(DISTINCT CASE WHEN seen.views > ? AND seen.voted THEN visits.visitor_hash END) AS voters',
            'SUM(CASE WHEN seen.shared_at IS NOT NULL THEN 1 ELSE 0 END) AS shares',
        ]), [0, 0]];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{views: int, viewers: int, voters: int, shares: int}
     */
    private static function counted(array $row): array
    {
        return [
            'views' => (int) ($row['views'] ?? 0),
            'viewers' => (int) ($row['viewers'] ?? 0),
            'voters' => (int) ($row['voters'] ?? 0),
            'shares' => (int) ($row['shares'] ?? 0),
        ];
    }
}
