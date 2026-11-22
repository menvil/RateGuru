<?php

namespace App\Queries\Analytics;

use App\Enums\GameEventType;
use App\Models\AnalyticsDirtyDay;
use App\Models\AnalyticsPerson;
use App\Models\AnalyticsVisit;
use App\Models\GameAttempt;
use App\Models\TrafficVisit;
use App\Models\TrafficVisitPost;
use App\Support\Analytics\AnalyticsVisitRow;
use App\Support\Traffic\Reports\TrafficReportFilter;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;

/**
 * What analytics:refresh-visits reads to bring analytics_visits up to date:
 * the visits of a network day, a block at a time in the order they were
 * recorded, read through the arrival index, with what each did with posts,
 * what was played on it and its session's first post; the rows already
 * written for them; the first days already known of their browsers; and the
 * days marked as changed. And what traffic:purge-expired moves a browser's
 * first day on to once the visits before it are gone. Read-only.
 */
final class AnalyticsRollupQuery
{
    /**
     * The visits of a day after the id given, the first ones recorded first,
     * at most so many: their columns a row is made from, what each did with
     * posts (AnalyticsVisitRow::postFigures()), what was played on each
     * (AnalyticsVisitRow::attemptFigures()) and, for each credited with an
     * account, the post its session opened first (AnalyticsVisitRow::sessionPost()).
     *
     * @return list<array<string, mixed>>
     */
    public function visits(string $day, int $afterId, int $limit): array
    {
        $window = new TrafficReportFilter($day, $day);

        $visits = TrafficVisit::query()
            ->where('network_day', $day)
            ->where('arrived_at', '>=', $window->arrivedFrom())
            ->where('arrived_at', '<', $window->arrivedBefore())
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->select(AnalyticsVisitRow::SOURCE_COLUMNS)
            ->toBase()
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();

        if ($visits === []) {
            return [];
        }

        $ids = array_column($visits, 'id');
        // What each visit did with posts, from its rows of them: one read for the block.
        $posts = TrafficVisitPost::query()
            ->whereIn('visit_id', $ids)
            ->toBase()
            ->get(['visit_id', 'views', 'voted', 'shared_at'])
            ->map(fn (object $post): array => (array) $post)
            ->groupBy('visit_id');
        // The attempts started on each visit, each once for every click on its result's share buttons, once with none: one read for the block.
        $attempts = GameAttempt::query()
            ->leftJoin('game_events as share', fn (JoinClause $join) => $join->on('share.attempt_id', '=', 'game_attempts.id')->where('share.type', GameEventType::ShareClicked->value))
            ->whereIn('game_attempts.traffic_visit_id', $ids)
            ->toBase()
            ->get(['game_attempts.traffic_visit_id', 'game_attempts.id', 'share.id as share_id'])
            ->map(fn (object $attempt): array => (array) $attempt)
            ->groupBy('traffic_visit_id');
        $sessions = $this->sessionPosts($visits);

        return array_map(fn (array $visit): array => [
            ...$visit,
            ...AnalyticsVisitRow::postFigures($posts->get($visit['id'], collect())->all()),
            ...AnalyticsVisitRow::attemptFigures($attempts->get($visit['id'], collect())->all()),
            'session_post_id' => $visit['account_at'] === null || $visit['entry_visit_id'] === null ? null
                : AnalyticsVisitRow::sessionPost($sessions->get($visit['entry_visit_id'], collect())->all()),
        ], $visits);
    }

    /**
     * The posts the sessions of the visits given credited with an account
     * opened — every visit sharing such a visit's entry — each with the moment
     * it was first opened, by the session's entry.
     *
     * @param  list<array<string, mixed>>  $visits
     * @return Collection<array-key, Collection<int, array<mixed>>>
     */
    private function sessionPosts(array $visits): Collection
    {
        $entries = [];

        foreach ($visits as $visit) {
            if ($visit['account_at'] !== null && $visit['entry_visit_id'] !== null) {
                $entries[(int) $visit['entry_visit_id']] = true;
            }
        }

        if ($entries === []) {
            return collect();
        }

        return TrafficVisitPost::query()
            ->join('traffic_visits as session_visit', 'session_visit.id', '=', 'traffic_visit_posts.visit_id')
            ->whereIn('session_visit.entry_visit_id', array_keys($entries))
            ->where('traffic_visit_posts.views', '>', 0)
            ->toBase()
            ->get(['session_visit.entry_visit_id', 'traffic_visit_posts.post_id', 'traffic_visit_posts.first_at'])
            ->map(fn (object $row): array => (array) $row)
            ->groupBy('entry_visit_id');
    }

    /**
     * The rows written for the visits given, by id.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, string|null>>
     */
    public function written(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = [];

        foreach (AnalyticsVisit::query()->whereIn('id', $ids)->select(AnalyticsVisitRow::COLUMNS)->toBase()->get() as $row) {
            $row = AnalyticsVisitRow::fromStored((array) $row);
            $rows[(int) $row['id']] = $row;
        }

        return $rows;
    }

    /**
     * The first days known of the browsers given, by their number.
     *
     * @param  list<int>  $keys
     * @return array<int, string>
     */
    public function firstDays(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        return AnalyticsPerson::query()
            ->whereIn('visitor_key', $keys)
            ->pluck('first_day', 'visitor_key')
            ->map(fn (mixed $day): string => (string) $day)
            ->all();
    }

    /**
     * The browsers whose first day known is before the day given that still
     * have a row whose page ran, each with the earliest network day of those
     * rows, by their number — for traffic:purge-expired once the visits
     * before it are gone. One read of every row, no index of them leading
     * with the browser: each browser's days, the earliest first, taken one
     * at a time — a browser that comes back every day has hundreds — so
     * only the earliest of each is held.
     *
     * @return array<int, string>
     */
    public function firstDaysLeft(string $before): array
    {
        $first = [];
        $days = AnalyticsVisit::query()
            ->whereIn('visitor_key', AnalyticsPerson::query()->where('first_day', '<', $before)->select('visitor_key'))
            ->where('opened', true)
            ->distinct()
            ->orderBy('network_day')
            ->select(['visitor_key', 'network_day'])
            ->toBase()
            ->cursor();

        foreach ($days as $day) {
            $first[(int) $day->visitor_key] ??= (string) $day->network_day;
        }

        return $first;
    }

    /**
     * The days marked as changed, the earliest first, each with the token of
     * its latest change.
     *
     * @return array<string, string>
     */
    public function dirtyDays(int $limit): array
    {
        return AnalyticsDirtyDay::query()
            ->orderBy('day')
            ->limit($limit)
            ->pluck('token', 'day')
            ->map(fn (mixed $token): string => (string) $token)
            ->all();
    }
}
