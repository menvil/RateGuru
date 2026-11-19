<?php

namespace App\Queries\Analytics;

use App\Contracts\Persistence\RawSqlPersistenceBoundary;
use App\Enums\AnalyticsDimension;
use App\Enums\GameEventType;
use App\Models\GameAttempt;
use App\Models\GameEvent;
use App\Models\TrafficVisit;
use App\Support\Analytics\ActivityDay;
use App\Support\Analytics\AnalyticsFilter;
use App\Support\Analytics\AnalyticsPeriod;
use App\Support\Analytics\AnalyticsSource;
use App\Support\Analytics\VisitorFigures;
use App\Support\Traffic\Capture\BotSignals;
use App\Support\Traffic\Money\TrafficMoney;
use App\Support\Traffic\Reports\TrafficReportFilter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * The people of Growth → Analytics, read straight from the visits — every
 * visit, a paid click, a game visit or one to the rest of the site — with
 * no totals kept aside: conditional distinct counts of browsers and sums
 * over the period's visits, with every value bound and every grouping
 * column named by AnalyticsDimension, never arithmetic on dates or a branch
 * per engine. Where a session came from is a column of each of its visits,
 * so nothing here joins the visit it began with.
 *
 * A visit counts once its page ran (opened_at): a click whose page never
 * did is no person. The period is its network days, read through a wider
 * span of arrivals so the arrival index narrows the scan first, as every
 * traffic report reads it.
 *
 *  - figures()       the people of the filter and what they did;
 *  - by()            the same per source, campaign, country … the most
 *                    people first, and the people of every group past the
 *                    top ones, or too small to list, counted together, each
 *                    once; a group asked for listed whatever its size;
 *  - series()        the people of each bucket of a chart;
 *  - returning()     those who came on two days of the period or before it;
 *  - quality()       what the people leave out: the visitors no tag or
 *                    referrer names, and the paid clicks that were crawlers
 *                    or whose page never ran, with their spend;
 *  - attempts()      the attempts played on the period's visits;
 *  - resultShares()  the clicks on a result's share buttons, and the results;
 *  - top()           the values a dimension's people came through, the most
 *                    first, with nothing counted — those beginning as asked,
 *                    when asked: what a filter offers;
 *  - of()            the figures of each of the values given of a dimension;
 *  - among()         the figures of each group of a dimension's columns
 *                    among the values given of its first — the tags of the
 *                    sessions of some campaigns;
 *  - pairs()         the people of each pair of values two dimensions held
 *                    together — the sources of each campaign;
 *  - spend()         what the paid clicks cost, their pages run or not,
 *                    split by one dimension or more in turn;
 *  - firstDays()     the first day each value given came on;
 *  - has()           whether the filter admits any visit at all;
 *  - recordedSince() when the first visit of all was recorded.
 *
 * Read-only.
 */
final class AnalyticsVisitsQuery implements RawSqlPersistenceBoundary
{
    public const int MOST_GROUPS = 2000;

    /** A split lists the visits whose dimension holds no value as a group like any other. */
    public const string UNNAMED_LISTED = 'listed';

    /** A split never lists the visits whose dimension holds no value: their people count in the rest. */
    public const string UNNAMED_IN_REST = 'rest';

    /** A split lists the visits whose dimension holds no value neither as a group nor in the rest: their people count on their own. */
    public const string UNNAMED_APART = 'apart';

    /** A split leaves the visits whose dimension holds no value out altogether: neither a group, nor in the rest, nor counted at all. */
    public const string UNNAMED_LEFT_OUT = 'out';

    /** How many groups the rest of a split leaves out in one block of its test (excluding()). */
    private const int EXCLUDED_BLOCK = 100;

    /** The figures of every visit the filter admits. */
    public function figures(AnalyticsFilter $filter): VisitorFigures
    {
        [$metrics, $bindings] = self::metrics($filter);

        return VisitorFigures::fromRow((array) $this->visits($filter)->selectRaw($metrics, $bindings)->toBase()->first());
    }

    /**
     * The visits the filter admits split by a dimension: a person counts in
     * each group they came through, so the groups can add up to more than
     * the figures of them all. The most people first, then by the value; at
     * most the limit given — MOST_GROUPS when none is, and never more — each
     * with at least the people given — and then the people of all the other
     * groups together, each once, as `rest` (none when no one is left). With
     * each group, how many visits it had and how many of them carried
     * campaign tags (AnalyticsSource::basis()).
     *
     * The visits whose dimension holds no value — its first column empty —
     * are a group like any other (UNNAMED_LISTED); or never one, their
     * people counted in the rest (UNNAMED_IN_REST); or in neither, their
     * people counted on their own as `unnamed` (UNNAMED_APART); or not
     * counted at all (UNNAMED_LEFT_OUT). Only apart is `unnamed` read; it is
     * none otherwise, and when no one is left.
     *
     * Groups to keep — each by its columns' values, a null one by being null
     * — are listed whatever the people given, so long as anyone came through
     * them, after the others and in place of the last listed ones not kept
     * themselves when the limit is reached; the rest is then what is no
     * longer listed. A kept group past those read is read on its own, one
     * more read; a group of no value is kept only while such visits are a
     * group (UNNAMED_LISTED).
     *
     * @param  list<array<string, int|string|null>>  $keep
     * @return array{rows: list<array{keys: array<string, int|string|null>, figures: VisitorFigures, visits: int, tagged: int}>, rest: array{figures: VisitorFigures, visits: int, tagged: int}|null, unnamed: array{figures: VisitorFigures, visits: int, tagged: int}|null}
     */
    public function by(AnalyticsFilter $filter, AnalyticsDimension $dimension, ?int $limit = null, int $least = 1, string $unnamed = self::UNNAMED_LISTED, array $keep = []): array
    {
        if (! in_array($unnamed, [self::UNNAMED_LISTED, self::UNNAMED_IN_REST, self::UNNAMED_APART, self::UNNAMED_LEFT_OUT], true)) {
            throw new InvalidArgumentException("No split treats the visits of no value as [{$unnamed}].");
        }

        [$metrics, $bindings] = self::metrics($filter);
        $columns = $dimension->columns();
        $limit = max(1, min($limit ?? self::MOST_GROUPS, self::MOST_GROUPS));
        $least = max(1, $least);
        $named = fn (Builder $query): Builder => $unnamed === self::UNNAMED_LISTED ? $query : $query->whereNotNull($columns[0]);

        $read = $named($this->visits($filter))
            ->groupBy(...$columns)
            ->select($columns)
            ->selectRaw($metrics, $bindings)
            ->orderByRaw('COUNT(DISTINCT visitor_hash) DESC', [])
            ->orderBy($columns[0])
            ->limit($limit)
            ->toBase()
            ->get()
            ->map(fn (object $row): array => ['keys' => self::keys((array) $row, $columns), ...self::counted((array) $row)])
            ->values()
            ->all();
        // The most people first: a group too small to be listed is followed only by others as small.
        $rows = array_values(array_filter($read, fn (array $row): bool => $row['figures']->visitors >= $least));
        $rows = $this->keeping($filter, $columns, $read, $rows, $keep, $limit, $unnamed);
        $left = count($read) >= $limit || count($rows) < count($read) || $unnamed === self::UNNAMED_IN_REST;
        $rest = null;

        if ($left) {
            $rest = self::counted((array) self::excluding(in_array($unnamed, [self::UNNAMED_APART, self::UNNAMED_LEFT_OUT], true) ? $named($this->visits($filter)) : $this->visits($filter), $columns, array_column($rows, 'keys'))
                ->selectRaw($metrics, $bindings)
                ->toBase()
                ->first());
        }

        $none = $unnamed !== self::UNNAMED_APART ? null : self::counted((array) $this->visits($filter)
            ->whereNull($columns[0])
            ->selectRaw($metrics, $bindings)
            ->toBase()
            ->first());

        return [
            'rows' => $rows,
            'rest' => $rest === null || $rest['figures']->isEmpty() ? null : $rest,
            'unnamed' => $none === null || $none['figures']->isEmpty() ? null : $none,
        ];
    }

    /**
     * The people of each bucket of a chart (AnalyticsPeriod::buckets()), in
     * the buckets' order: a person on two days counts on each. Days are the
     * visits' network days; blocks of days are read between their first
     * and last day; hours by the moment the visit arrived, the first hour
     * taking any earlier arrival of the day's visits and the last any later
     * one — a network day in another time zone than UTC runs past either end.
     *
     * @param  list<array{unit: string, fromDay: string, toDay: string, from: CarbonImmutable, before: CarbonImmutable}>  $buckets
     * @return list<int>
     */
    public function series(AnalyticsFilter $filter, array $buckets): array
    {
        if ($buckets === []) {
            return [];
        }

        if ($buckets[0]['unit'] === AnalyticsPeriod::DAY) {
            $byDay = $this->visits($filter)
                ->groupBy('network_day')
                ->select('network_day')
                ->selectRaw('COUNT(DISTINCT visitor_hash) AS visitors', [])
                ->toBase()
                ->get()
                ->mapWithKeys(fn (object $row): array => [(string) $row->network_day => (int) $row->visitors]);

            return array_map(fn (array $bucket): int => (int) ($byDay[$bucket['fromDay']] ?? 0), $buckets);
        }

        $counts = [];
        $bindings = [];
        $last = array_key_last($buckets);

        foreach ($buckets as $index => $bucket) {
            if ($bucket['unit'] === AnalyticsPeriod::HOUR) {
                $bounds = match (true) {
                    $index === 0 => ['arrived_at < ?', [$bucket['before']]],
                    $index === $last => ['arrived_at >= ?', [$bucket['from']]],
                    default => ['arrived_at >= ? AND arrived_at < ?', [$bucket['from'], $bucket['before']]],
                };
            } else {
                $bounds = ['network_day >= ? AND network_day <= ?', [$bucket['fromDay'], $bucket['toDay']]];
            }

            $counts[] = 'COUNT(DISTINCT CASE WHEN '.$bounds[0].' THEN visitor_hash END) AS bucket_'.$index;
            array_push($bindings, ...$bounds[1]);
        }

        $row = (array) $this->visits($filter)->selectRaw(implode(', ', $counts), $bindings)->toBase()->first();

        return array_map(fn (int $index): int => (int) ($row['bucket_'.$index] ?? 0), array_keys($buckets));
    }

    /**
     * The people of the filter who came back: on two network days of the
     * period or more, or seen before it — a visit of the browser's, from
     * anywhere, whose page ran on an earlier day.
     */
    public function returning(AnalyticsFilter $filter): int
    {
        $people = $this->visits($filter)
            ->groupBy('visitor_hash')
            ->select('visitor_hash')
            ->selectRaw('COUNT(DISTINCT network_day) AS days', []);
        $earlier = TrafficVisit::query()
            ->from('traffic_visits as earlier')
            ->whereColumn('earlier.visitor_hash', 'period_people.visitor_hash')
            ->where('earlier.network_day', '<', $filter->fromDay())
            ->whereNotNull('earlier.opened_at');

        return TrafficVisit::query()
            ->fromSub($people, 'period_people')
            ->where(fn (Builder $either) => $either->where('period_people.days', '>', 1)->orWhereExists($earlier))
            ->count();
    }

    /**
     * What the figures leave out, over every visit the filter admits, its
     * page run or not: the visitors no tag or referrer names (Direct) beside
     * all of them; the paid clicks, those a crawler or link preview made, and
     * the others whose page never ran; and what the paid clicks cost, how
     * much of it estimated.
     *
     * @return array{visitors: int, direct: int, paidClicks: int, crawlers: int, neverRan: int, spend: string, spendEstimated: string}
     */
    public function quality(AnalyticsFilter $filter): array
    {
        $row = (array) $this->visits($filter, openedOnly: false)
            ->selectRaw(implode(', ', [
                'COUNT(DISTINCT CASE WHEN opened_at IS NOT NULL THEN visitor_hash END) AS visitors',
                'COUNT(DISTINCT CASE WHEN opened_at IS NOT NULL AND source_key = ? THEN visitor_hash END) AS direct',
                'SUM(CASE WHEN source_id IS NOT NULL THEN 1 ELSE 0 END) AS paid_clicks',
                'SUM(CASE WHEN source_id IS NOT NULL AND (bot_flags & ?) <> 0 THEN 1 ELSE 0 END) AS crawlers',
                'SUM(CASE WHEN source_id IS NOT NULL AND opened_at IS NULL AND (bot_flags & ?) = 0 THEN 1 ELSE 0 END) AS never_ran',
                'SUM(CASE WHEN source_id IS NOT NULL THEN spend ELSE 0 END) AS spend',
                'SUM(CASE WHEN source_id IS NOT NULL AND spend_estimated THEN spend ELSE 0 END) AS spend_estimated',
            ]), [AnalyticsSource::DIRECT, BotSignals::CRAWLER, BotSignals::CRAWLER])
            ->toBase()
            ->first();

        return [
            'visitors' => (int) ($row['visitors'] ?? 0),
            'direct' => (int) ($row['direct'] ?? 0),
            'paidClicks' => (int) ($row['paid_clicks'] ?? 0),
            'crawlers' => (int) ($row['crawlers'] ?? 0),
            'neverRan' => (int) ($row['never_ran'] ?? 0),
            'spend' => TrafficMoney::stored($row['spend'] ?? 0),
            'spendEstimated' => TrafficMoney::stored($row['spend_estimated'] ?? 0),
        ];
    }

    /** How many attempts were started on the visits the filter admits: a game's starters play some more than once. */
    public function attempts(AnalyticsFilter $filter): int
    {
        return GameAttempt::query()->whereIn('traffic_visit_id', $this->visitIds($filter))->count();
    }

    /**
     * The clicks on a result's share buttons, of the attempts started on the
     * visits the filter admits, and how many results they shared — different
     * attempts with a click.
     *
     * @return array{clicks: int, results: int}
     */
    public function resultShares(AnalyticsFilter $filter): array
    {
        $row = (array) GameEvent::query()
            ->where('type', GameEventType::ShareClicked->value)
            ->whereIn('attempt_id', GameAttempt::query()->whereIn('traffic_visit_id', $this->visitIds($filter))->select('id'))
            ->selectRaw('COUNT(*) AS clicks, COUNT(DISTINCT attempt_id) AS results', [])
            ->toBase()
            ->first();

        return ['clicks' => (int) ($row['clicks'] ?? 0), 'results' => (int) ($row['results'] ?? 0)];
    }

    /**
     * The groups of a dimension the most people came through, the most first
     * and then by the value, at most the limit given: only their values, by
     * column, what a filter of the page offers to choose from. Given a
     * beginning, only the groups whose first column begins with it are
     * ranked — the campaigns named by their tags ("tag:"), say — so none of
     * the others takes a place of the limit; a beginning that is empty or
     * holds a LIKE wildcard (% or _) or a backslash is refused, since it
     * would match more than itself.
     *
     * @return list<array<string, int|string|null>>
     */
    public function top(AnalyticsFilter $filter, AnalyticsDimension $dimension, int $limit, ?string $prefix = null): array
    {
        $columns = $dimension->columns();

        if ($prefix !== null && ($prefix === '' || strpbrk($prefix, '%_\\') !== false)) {
            throw new InvalidArgumentException("No values are offered by the beginning [{$prefix}].");
        }

        return $this->visits($filter)
            ->when($prefix !== null, fn (Builder $begins) => $begins->where($columns[0], 'like', $prefix.'%'))
            ->groupBy(...$columns)
            ->select($columns)
            ->orderByRaw('COUNT(DISTINCT visitor_hash) DESC', [])
            ->orderBy($columns[0])
            ->limit(max(1, min($limit, self::MOST_GROUPS)))
            ->toBase()
            ->get()
            ->map(fn (object $row): array => self::keys((array) $row, $columns))
            ->values()
            ->all();
    }

    /**
     * The figures of each value given of a dimension of one column, among
     * the visits the filter admits, by the value: a value no one came
     * through is left out.
     *
     * @param  list<int|string>  $values
     * @return array<int|string, array{figures: VisitorFigures, visits: int, tagged: int}>
     */
    public function of(AnalyticsFilter $filter, AnalyticsDimension $dimension, array $values): array
    {
        $column = self::single($dimension);

        if ($values === []) {
            return [];
        }

        [$metrics, $bindings] = self::metrics($filter);
        $groups = [];

        foreach ($this->visits($filter)->whereIn($column, $values)->groupBy($column)->select($column)->selectRaw($metrics, $bindings)->toBase()->get() as $row) {
            $value = self::keys((array) $row, [$column])[$column];

            if ($value !== null) {
                $groups[$value] = self::counted((array) $row);
            }
        }

        return $groups;
    }

    /**
     * The figures of each group of a dimension's columns among the visits the
     * filter admits whose first column holds one of the values given — every
     * set of tags the sessions of some campaigns came by, say — a column
     * holding no value grouped as none. The most people first, then by the
     * values — where a group of none falls among them is the engine's own;
     * at most MOST_GROUPS. Given groups to read only — each by its columns'
     * values, a null one by being null — no other group is read, so a group
     * asked for is never crowded out by bigger ones it wasn't.
     *
     * @param  list<int|string>  $firstValues
     * @param  list<array<string, int|string|null>>  $only
     * @return list<array{keys: array<string, int|string|null>, figures: VisitorFigures, visits: int, tagged: int}>
     */
    public function among(AnalyticsFilter $filter, AnalyticsDimension $dimension, array $firstValues, array $only = []): array
    {
        $columns = $dimension->columns();

        if ($firstValues === []) {
            return [];
        }

        [$metrics, $bindings] = self::metrics($filter);

        $query = $this->visits($filter)
            ->whereIn($columns[0], $firstValues)
            ->when($only !== [], fn (Builder $asked) => self::ofGroups($asked, $columns, $only))
            ->groupBy(...$columns)
            ->select($columns)
            ->selectRaw($metrics, $bindings)
            ->orderByRaw('COUNT(DISTINCT visitor_hash) DESC', []);

        foreach ($columns as $column) {
            $query->orderBy($column);
        }

        return $query
            ->limit(self::MOST_GROUPS)
            ->toBase()
            ->get()
            ->map(fn (object $row): array => ['keys' => self::keys((array) $row, $columns), ...self::counted((array) $row)])
            ->values()
            ->all();
    }

    /**
     * The people of each pair of values two dimensions of one column each
     * held together on the visits the filter admits, among the values given
     * of the first and with a value of both — the sources each campaign came
     * through, say. The most people first, then by the values; at most
     * MOST_GROUPS.
     *
     * @param  list<int|string>  $firstValues
     * @return list<array{keys: array<string, int|string|null>, visitors: int}>
     */
    public function pairs(AnalyticsFilter $filter, AnalyticsDimension $first, AnalyticsDimension $second, array $firstValues): array
    {
        $columns = [self::single($first), self::single($second)];

        if ($firstValues === []) {
            return [];
        }

        return $this->visits($filter)
            ->whereIn($columns[0], $firstValues)
            ->whereNotNull($columns[1])
            ->groupBy(...$columns)
            ->select($columns)
            ->selectRaw('COUNT(DISTINCT visitor_hash) AS visitors', [])
            ->orderByRaw('COUNT(DISTINCT visitor_hash) DESC', [])
            ->orderBy($columns[0])
            ->orderBy($columns[1])
            ->limit(self::MOST_GROUPS)
            ->toBase()
            ->get()
            ->map(fn (object $row): array => ['keys' => self::keys((array) $row, $columns), 'visitors' => (int) ((array) $row)['visitors']])
            ->values()
            ->all();
    }

    /**
     * What the paid clicks the filter admits cost — every one, its page run
     * or not, as Traffic sources counts spend — split by the columns of the
     * dimensions given (of one column each), in turn, among the values given
     * of the first, or every value of it: sums, so the groups of a split add
     * up to the whole, and the groups of a value of the first add up to its
     * own. The visits whose first column holds no value are left out; how
     * much of each sum was estimated comes with it.
     *
     * @param  non-empty-list<AnalyticsDimension>  $dimensions
     * @param  list<int|string>|null  $firstValues
     * @return list<array{keys: array<string, int|string|null>, spend: string, estimated: string}>
     */
    public function spend(AnalyticsFilter $filter, array $dimensions, ?array $firstValues = null): array
    {
        $columns = array_map(self::single(...), $dimensions);

        if ($firstValues === []) {
            return [];
        }

        return $this->visits($filter, openedOnly: false)
            ->whereNotNull('source_id')
            ->whereNotNull($columns[0])
            ->when($firstValues !== null, fn (Builder $among) => $among->whereIn($columns[0], $firstValues))
            ->groupBy(...$columns)
            ->select($columns)
            ->selectRaw('SUM(spend) AS spend, SUM(CASE WHEN spend_estimated THEN spend ELSE 0 END) AS spend_estimated', [])
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'keys' => self::keys((array) $row, $columns),
                'spend' => TrafficMoney::stored(((array) $row)['spend'] ?? 0),
                'estimated' => TrafficMoney::stored(((array) $row)['spend_estimated'] ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * The first network day each value given of a dimension of one column
     * came on, between the days given — when a campaign began, say — by any
     * visit whose page ran, whatever else a filter would narrow; a value
     * that never came in those days is left out.
     *
     * @param  list<int|string>  $values
     * @return array<int|string, string>
     */
    public function firstDays(AnalyticsDimension $dimension, array $values, string $fromDay, string $toDay): array
    {
        $column = self::single($dimension);

        if ($values === []) {
            return [];
        }

        $window = new TrafficReportFilter($fromDay, $toDay);
        $days = [];

        foreach (TrafficVisit::query()
            ->whereBetween('network_day', [$fromDay, $toDay])
            ->where('arrived_at', '>=', $window->arrivedFrom())
            ->where('arrived_at', '<', $window->arrivedBefore())
            ->whereNotNull('opened_at')
            ->whereIn($column, $values)
            ->groupBy($column)
            ->select($column)
            ->selectRaw('MIN(network_day) AS first_day', [])
            ->toBase()
            ->get() as $row) {
            $value = self::keys((array) $row, [$column])[$column];

            if ($value !== null) {
                $days[$value] = (string) ((array) $row)['first_day'];
            }
        }

        return $days;
    }

    /** Whether the filter admits any visit whose page ran. */
    public function has(AnalyticsFilter $filter): bool
    {
        return $this->visits($filter)->exists();
    }

    /** When the first visit of all was recorded, a paid click or a page's; none before the first. */
    public function recordedSince(): ?CarbonImmutable
    {
        $first = TrafficVisit::query()->min('arrived_at');

        return $first === null ? null : CarbonImmutable::parse((string) $first, 'UTC');
    }

    /** @return Builder<TrafficVisit> */
    private function visits(AnalyticsFilter $filter, bool $openedOnly = true): Builder
    {
        $query = TrafficVisit::query()
            ->whereBetween('network_day', [$filter->fromDay(), $filter->toDay()])
            ->where('arrived_at', '>=', $filter->arrivedFrom())
            ->where('arrived_at', '<', $filter->arrivedBefore())
            ->when($openedOnly, fn (Builder $opened) => $opened->whereNotNull('opened_at'));

        foreach ($filter->conditions() as [$column, $value]) {
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }

        return $query;
    }

    /** The one column of a dimension a read takes one column of; a dimension of two is refused. */
    private static function single(AnalyticsDimension $dimension): string
    {
        $columns = $dimension->columns();

        if (count($columns) !== 1) {
            throw new InvalidArgumentException("The {$dimension->value} dimension reads more than one column.");
        }

        return $columns[0];
    }

    /** @return Builder<TrafficVisit> the ids of the visits the filter admits */
    private function visitIds(AnalyticsFilter $filter): Builder
    {
        return $this->visits($filter)->select('id');
    }

    /**
     * The rows of a split with the groups to keep among them (by()): each one
     * not listed yet that anyone came through — found among the groups read,
     * else read on its own, only while the read was full, since only then
     * can it have been left out — after the others, in place of the last
     * listed ones not kept themselves once the limit is reached.
     *
     * @param  list<string>  $columns
     * @param  list<array{keys: array<string, int|string|null>, figures: VisitorFigures, visits: int, tagged: int}>  $read
     * @param  list<array{keys: array<string, int|string|null>, figures: VisitorFigures, visits: int, tagged: int}>  $rows
     * @param  list<array<string, int|string|null>>  $keep
     * @return list<array{keys: array<string, int|string|null>, figures: VisitorFigures, visits: int, tagged: int}>
     */
    private function keeping(AnalyticsFilter $filter, array $columns, array $read, array $rows, array $keep, int $limit, string $unnamed): array
    {
        $kept = [];

        foreach ($keep as $group) {
            $keys = self::keys($group, $columns);

            if (! in_array($keys, $kept, true) && ($keys[$columns[0]] !== null || $unnamed === self::UNNAMED_LISTED)) {
                $kept[] = $keys;
            }
        }

        $listed = array_column($rows, 'keys');
        $wanted = array_values(array_filter($kept, fn (array $keys): bool => ! in_array($keys, $listed, true)));

        if ($wanted === []) {
            return $rows;
        }

        $found = array_values(array_filter($read, fn (array $row): bool => in_array($row['keys'], $wanted, true)));
        $unread = array_values(array_filter($wanted, fn (array $keys): bool => ! in_array($keys, array_column($found, 'keys'), true)));

        if ($unread !== [] && count($read) >= $limit) {
            [$metrics, $bindings] = self::metrics($filter);

            // Past every group read, so after those found among them: the most people first still.
            array_push($found, ...self::ofGroups($this->visits($filter), $columns, $unread)
                ->groupBy(...$columns)
                ->select($columns)
                ->selectRaw($metrics, $bindings)
                ->orderByRaw('COUNT(DISTINCT visitor_hash) DESC', [])
                ->toBase()
                ->get()
                ->map(fn (object $row): array => ['keys' => self::keys((array) $row, $columns), ...self::counted((array) $row)])
                ->values()
                ->all());
        }

        // A kept group already listed keeps its place: only the listed ones not kept give way, the last first.
        $held = count(array_filter($listed, fn (array $keys): bool => in_array($keys, $kept, true)));
        $found = array_slice($found, 0, max(0, $limit - $held));

        for ($index = count($rows) - 1; $index >= 0 && count($rows) + count($found) > $limit; $index--) {
            if (! in_array($rows[$index]['keys'], $kept, true)) {
                array_splice($rows, $index, 1);
            }
        }

        return [...$rows, ...$found];
    }

    /**
     * The visits of any of the groups given: a visit whose columns all hold
     * a group's values — a null value by being null.
     *
     * @param  Builder<TrafficVisit>  $query
     * @param  list<string>  $columns
     * @param  list<array<string, int|string|null>>  $groups
     * @return Builder<TrafficVisit>
     */
    private static function ofGroups(Builder $query, array $columns, array $groups): Builder
    {
        return $query->where(function (Builder $any) use ($columns, $groups): void {
            foreach ($groups as $keys) {
                $any->orWhere(function (Builder $same) use ($columns, $keys): void {
                    foreach ($columns as $column) {
                        $value = $keys[$column] ?? null;
                        $value === null ? $same->whereNull($column) : $same->where($column, $value);
                    }
                });
            }
        });
    }

    /**
     * The visits of none of the groups given: for each group, a visit whose
     * columns all hold its values — a null value by being null — is left out.
     * The groups are tested a block of EXCLUDED_BLOCK at a time, so even
     * MOST_GROUPS of them nest no deeper than SQLite's parser allows (1,000).
     *
     * @param  Builder<TrafficVisit>  $query
     * @param  list<string>  $columns
     * @param  list<array<string, int|string|null>>  $groups
     * @return Builder<TrafficVisit>
     */
    private static function excluding(Builder $query, array $columns, array $groups): Builder
    {
        if ($groups === []) {
            return $query;
        }

        return $query->whereNot(function (Builder $any) use ($columns, $groups): void {
            foreach (array_chunk($groups, self::EXCLUDED_BLOCK) as $block) {
                $any->orWhere(function (Builder $inBlock) use ($columns, $block): void {
                    foreach ($block as $keys) {
                        // Each column compared so a null never makes the whole test unknown.
                        $inBlock->orWhere(function (Builder $same) use ($columns, $keys): void {
                            foreach ($columns as $column) {
                                $value = $keys[$column] ?? null;
                                $value === null ? $same->whereNull($column) : $same->whereNotNull($column)->where($column, $value);
                            }
                        });
                    }
                });
            }
        });
    }

    /**
     * The figures of a row, as SQL: distinct browsers for each step, the
     * actions as made, the spend; and the visits with those that carried
     * campaign tags. Matured accounts are those signed up before the moment
     * their days to activate are over by today.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private static function metrics(AnalyticsFilter $filter): array
    {
        $parts = [
            ['COUNT(DISTINCT visitor_hash) AS visitors', []],
            ['COUNT(DISTINCT CASE WHEN NOT known THEN visitor_hash END) AS new_visitors', []],
            ['COUNT(DISTINCT CASE WHEN started_at IS NOT NULL THEN visitor_hash END) AS starters', []],
            ['COUNT(DISTINCT CASE WHEN completed_at IS NOT NULL THEN visitor_hash END) AS completers', []],
            ['COUNT(DISTINCT CASE WHEN goal_at IS NOT NULL THEN visitor_hash END) AS goal', []],
            ['COUNT(DISTINCT CASE WHEN signup_form_at IS NOT NULL THEN visitor_hash END) AS signup_form', []],
            ['COUNT(DISTINCT CASE WHEN signup_at IS NOT NULL THEN visitor_hash END) AS signups', []],
            ['COUNT(DISTINCT CASE WHEN account_at IS NOT NULL THEN visitor_hash END) AS accounts', []],
            ['COUNT(DISTINCT CASE WHEN activated_at IS NOT NULL THEN visitor_hash END) AS activated', []],
            ['COUNT(DISTINCT CASE WHEN account_at IS NOT NULL AND signup_at < ? THEN visitor_hash END) AS matured_accounts', [ActivityDay::maturedBefore($filter->period->today, (int) config('analytics.activation_days'))]],
            ['SUM(votes) AS votes', []],
            ['COUNT(DISTINCT CASE WHEN votes > ? THEN visitor_hash END) AS voters', [0]],
            ['SUM(comments) AS comments', []],
            ['COUNT(DISTINCT CASE WHEN comments > ? THEN visitor_hash END) AS commenters', [0]],
            ['SUM(posts_created) AS posts', []],
            ['SUM(spend) AS spend', []],
            ['SUM(CASE WHEN spend_estimated THEN spend ELSE 0 END) AS spend_estimated', []],
            ['COUNT(*) AS visits', []],
            ['SUM(CASE WHEN utm_source IS NOT NULL OR utm_medium IS NOT NULL THEN 1 ELSE 0 END) AS tagged', []],
        ];

        return [implode(', ', array_column($parts, 0)), array_merge(...array_column($parts, 1))];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{figures: VisitorFigures, visits: int, tagged: int}
     */
    private static function counted(array $row): array
    {
        return ['figures' => VisitorFigures::fromRow($row), 'visits' => (int) ($row['visits'] ?? 0), 'tagged' => (int) ($row['tagged'] ?? 0)];
    }

    /**
     * A group's values by column, read the same whatever the engine returned:
     * the ids of a game and a version as numbers.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $columns
     * @return array<string, int|string|null>
     */
    private static function keys(array $row, array $columns): array
    {
        $keys = [];

        foreach ($columns as $column) {
            $value = $row[$column] ?? null;
            $keys[$column] = match (true) {
                $value === null => null,
                str_ends_with($column, '_id') => (int) $value,
                default => (string) $value,
            };
        }

        return $keys;
    }
}
