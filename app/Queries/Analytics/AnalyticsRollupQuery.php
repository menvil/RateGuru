<?php

namespace App\Queries\Analytics;

use App\Models\AnalyticsDirtyDay;
use App\Models\AnalyticsPerson;
use App\Models\AnalyticsVisit;
use App\Models\TrafficVisit;
use App\Models\TrafficVisitPost;
use App\Support\Analytics\AnalyticsVisitRow;
use App\Support\Traffic\Reports\TrafficReportFilter;

/**
 * What analytics:refresh-visits reads to bring analytics_visits up to date:
 * the visits of a network day, a block at a time in the order they were
 * recorded, read through the arrival index; the rows already written for
 * them; the first days already known of their browsers; and the days marked
 * as changed. Read-only.
 */
final class AnalyticsRollupQuery
{
    /**
     * The visits of a day after the id given, the first ones recorded first,
     * at most so many: their columns a row is made from, and what each did
     * with posts (AnalyticsVisitRow::postFigures()).
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

        // What each visit did with posts, from its rows of them: one read for the block.
        $posts = $visits === [] ? collect() : TrafficVisitPost::query()
            ->whereIn('visit_id', array_column($visits, 'id'))
            ->toBase()
            ->get(['visit_id', 'views', 'voted', 'shared_at'])
            ->map(fn (object $post): array => (array) $post)
            ->groupBy('visit_id');

        return array_map(fn (array $visit): array => [...$visit, ...AnalyticsVisitRow::postFigures($posts->get($visit['id'], collect())->all())], $visits);
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
