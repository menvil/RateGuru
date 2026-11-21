<?php

namespace App\Queries\Analytics;

use App\Contracts\Persistence\RawSqlPersistenceBoundary;
use App\Enums\AnalyticsDimension;
use App\Enums\GameEventType;
use App\Models\AnalyticsCell;
use App\Models\AnalyticsCellAccount;
use App\Models\AnalyticsVisit;
use App\Models\GameAttempt;
use App\Models\GameEvent;
use App\Models\TrafficVisit;
use App\Support\Analytics\ActivityDay;
use App\Support\Analytics\AnalyticsCellLevel;
use App\Support\Analytics\AnalyticsFilter;
use App\Support\Analytics\AnalyticsPeriod;
use App\Support\Analytics\AnalyticsSource;
use App\Support\Analytics\VisitorFigures;
use App\Support\Traffic\Capture\BotSignals;
use App\Support\Traffic\Money\TrafficMoney;
use App\Support\Traffic\Reports\TrafficReportFilter;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
 * The visits are read from Analytics' own narrow rows of them
 * (analytics_visits: the browser a number, each step a flag) whenever
 * AnalyticsRollupGate says they are filled and up to date, and from
 * traffic_visits otherwise — the same figures either way, from the same
 * conditions on the same days and arrivals. And where the gate allows the
 * cells of each day's people (analytics_cells) and a read fits one of their
 * levels — its whole days, every column it filters or splits by in the level
 * (AnalyticsCellLevel) — figures(), by() and top() read those instead: each
 * day's people a roaring bitmap, a period's the union of its days', the same
 * figures again, in a fraction of the time.
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
 *  - top()           the values a dimension's visits came by, the most
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
 *  - recordedSince() when the first visit of all was recorded;
 *  - firstDay()      the first network day of all a visit came on: where
 *                    a report's All time starts.
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

    /** @var array<string, array{0: string, 1: string}> each test a figure makes of a visit, on traffic_visits and on Analytics' own rows */
    private const array TESTS = [
        'opened' => ['opened_at IS NOT NULL', 'opened'],
        'started' => ['started_at IS NOT NULL', 'started'],
        'completed' => ['completed_at IS NOT NULL', 'completed'],
        'goal' => ['goal_at IS NOT NULL', 'goal'],
        'signup_form' => ['signup_form_at IS NOT NULL', 'signup_form'],
        'signed_up' => ['signup_at IS NOT NULL', 'signed_up'],
        'account' => ['account_at IS NOT NULL', 'account'],
        'activated' => ['activated_at IS NOT NULL', 'activated'],
        'matured' => ['account_at IS NOT NULL AND signup_at < ?', 'account AND signup_at < ?'],
        'tagged' => ['utm_source IS NOT NULL OR utm_medium IS NOT NULL', 'tagged'],
        'paid' => ['source_id IS NOT NULL', 'source_id IS NOT NULL'],
        'crawler' => ['(bot_flags & ?) <> 0', 'crawler'],
        'not_crawler' => ['(bot_flags & ?) = 0', 'NOT crawler'],
        'unopened' => ['opened_at IS NULL', 'NOT opened'],
    ];

    public function __construct(private readonly AnalyticsRollupGate $gate) {}

    /** The figures of every visit the filter admits. */
    public function figures(AnalyticsFilter $filter): VisitorFigures
    {
        $level = $this->level($filter);
        [$metrics, $bindings] = $this->metrics($filter, $level);
        $figures = VisitorFigures::fromRow((array) $this->rows($filter, $level)->selectRaw($metrics, $bindings)->toBase()->first());

        return $level === null ? $figures : $figures->withMaturedAccounts($this->matured($this->accountsOf($filter, $level)));
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

        $columns = $dimension->columns();
        $level = $this->level($filter, $columns);
        [$metrics, $bindings] = $this->metrics($filter, $level);
        $limit = max(1, min($limit ?? self::MOST_GROUPS, self::MOST_GROUPS));
        $least = max(1, $least);
        $named = fn (Builder $query): Builder => $unnamed === self::UNNAMED_LISTED ? $query : $query->whereNotNull($columns[0]);

        $read = $named($this->rows($filter, $level))
            ->groupBy(...$columns)
            ->select($columns)
            ->selectRaw($metrics, $bindings)
            ->orderByRaw($this->peopleOrder($level).' DESC', [])
            ->orderBy($columns[0])
            ->limit($limit)
            ->toBase()
            ->get()
            ->map(fn (object $row): array => ['keys' => self::keys((array) $row, $columns), ...self::counted((array) $row)])
            ->values()
            ->all();
        // The most people first: a group too small to be listed is followed only by others as small.
        $rows = array_values(array_filter($read, fn (array $row): bool => $row['figures']->visitors >= $least));
        $rows = $this->keeping($filter, $level, $columns, $read, $rows, $keep, $limit, $unnamed);
        $left = count($read) >= $limit || count($rows) < count($read) || $unnamed === self::UNNAMED_IN_REST;
        $listed = array_column($rows, 'keys');
        $rest = null;

        if ($left) {
            $rest = self::counted((array) self::restOf($this->rows($filter, $level), $columns, $listed, $unnamed)
                ->selectRaw($metrics, $bindings)
                ->toBase()
                ->first());
        }

        $none = $unnamed !== self::UNNAMED_APART ? null : self::counted((array) $this->rows($filter, $level)
            ->whereNull($columns[0])
            ->selectRaw($metrics, $bindings)
            ->toBase()
            ->first());

        // The cells keep no account's sign-up day beside its visit's: the
        // matured accounts of each group, of the rest and of no value come
        // from the accounts kept by that day, the same groups and conditions.
        if ($level !== null) {
            $rows = $this->maturing($rows, $filter, $level, $columns);

            if ($rest !== null) {
                $rest['figures'] = $rest['figures']->withMaturedAccounts($this->matured(self::restOf($this->accountsOf($filter, $level), $columns, $listed, $unnamed)));
            }

            if ($none !== null) {
                $none['figures'] = $none['figures']->withMaturedAccounts($this->matured($this->accountsOf($filter, $level)->whereNull($columns[0])));
            }
        }

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

        // Hours are moments of arrival, which the cells don't keep: only days and blocks of them read the cells.
        $level = $buckets[0]['unit'] === AnalyticsPeriod::HOUR ? null : $this->level($filter);

        if ($buckets[0]['unit'] === AnalyticsPeriod::DAY) {
            $byDay = $this->rows($filter, $level)
                ->groupBy('network_day')
                ->select('network_day')
                ->selectRaw($this->peopleOrder($level).' AS visitors', [])
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

            $counts[] = ($level === null
                ? 'COUNT(DISTINCT CASE WHEN '.$bounds[0].' THEN '.$this->person().' END)'
                : 'COALESCE(rb_cardinality(rb_or_agg(visitors::roaringbitmap) FILTER (WHERE '.$bounds[0].')), 0)').' AS bucket_'.$index;
            array_push($bindings, ...$bounds[1]);
        }

        $row = (array) $this->rows($filter, $level)->selectRaw(implode(', ', $counts), $bindings)->toBase()->first();

        return array_map(fn (int $index): int => (int) ($row['bucket_'.$index] ?? 0), array_keys($buckets));
    }

    /**
     * The people of the filter who came back: on two network days of the
     * period or more, or seen before it — a visit of the browser's, from
     * anywhere, whose page ran on an earlier day.
     */
    public function returning(AnalyticsFilter $filter): int
    {
        $person = $this->person();
        $people = $this->visits($filter)
            ->groupBy($person)
            ->select($person)
            ->selectRaw('COUNT(DISTINCT network_day) AS days', []);

        // Analytics' own rows know each browser's first day: seen before is a first day before the period's.
        if ($this->gate->reads()) {
            return AnalyticsVisit::query()
                ->fromSub($people, 'period_people')
                ->join('analytics_people as person', 'person.visitor_key', '=', 'period_people.visitor_key')
                ->where(fn (Builder $either) => $either->where('period_people.days', '>', 1)->orWhere('person.first_day', '<', $filter->fromDay()))
                ->count();
        }

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
                'COUNT(DISTINCT CASE WHEN '.$this->test('opened').' THEN '.$this->person().' END) AS visitors',
                'COUNT(DISTINCT CASE WHEN '.$this->test('opened').' AND source_key = ? THEN '.$this->person().' END) AS direct',
                'SUM(CASE WHEN '.$this->test('paid').' THEN 1 ELSE 0 END) AS paid_clicks',
                'SUM(CASE WHEN '.$this->test('paid').' AND '.$this->test('crawler').' THEN 1 ELSE 0 END) AS crawlers',
                'SUM(CASE WHEN '.$this->test('paid').' AND '.$this->test('unopened').' AND '.$this->test('not_crawler').' THEN 1 ELSE 0 END) AS never_ran',
                'SUM(CASE WHEN '.$this->test('paid').' THEN spend ELSE 0 END) AS spend',
                'SUM(CASE WHEN '.$this->test('paid').' AND spend_estimated THEN spend ELSE 0 END) AS spend_estimated',
            ]), $this->gate->reads() ? [AnalyticsSource::DIRECT] : [AnalyticsSource::DIRECT, BotSignals::CRAWLER, BotSignals::CRAWLER])
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
     * The groups of a dimension the most visits came by, the most first and
     * then by the value, at most the limit given: only their values, by
     * column, what a filter of the page offers to choose from. Ranked by
     * visits, not people: a list to choose from needs no browser counted
     * once, and counting each browser once sorts every visit of the period.
     * Given a beginning, only the groups whose first column begins with it
     * are ranked — the campaigns named by their tags ("tag:"), say — so none
     * of the others takes a place of the limit; a beginning that is empty or
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

        $level = $this->level($filter, $columns);

        return $this->rows($filter, $level)
            ->when($prefix !== null, fn (Builder $begins) => $begins->where($columns[0], 'like', $prefix.'%'))
            ->groupBy(...$columns)
            ->select($columns)
            ->orderByRaw($level === null ? 'COUNT(*) DESC' : 'SUM(visits) DESC', [])
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

        $level = $this->level($filter, [$column]);
        [$metrics, $bindings] = $this->metrics($filter, $level);
        $read = $this->rows($filter, $level)
            ->whereIn($column, $values)
            ->groupBy($column)
            ->select($column)
            ->selectRaw($metrics, $bindings)
            ->toBase()
            ->get()
            ->map(fn (object $row): array => ['keys' => self::keys((array) $row, [$column]), ...self::counted((array) $row)])
            ->values()
            ->all();
        $groups = [];

        foreach ($this->maturing($read, $filter, $level, [$column], fn (Builder $accounts): Builder => $accounts->whereIn($column, $values)) as $row) {
            $value = $row['keys'][$column];

            if ($value !== null) {
                $groups[$value] = ['figures' => $row['figures'], 'visits' => $row['visits'], 'tagged' => $row['tagged']];
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

        $level = $this->level($filter, $columns);
        [$metrics, $bindings] = $this->metrics($filter, $level);
        $narrow = fn (Builder $query): Builder => $query
            ->whereIn($columns[0], $firstValues)
            ->when($only !== [], fn (Builder $asked) => self::ofGroups($asked, $columns, $only));

        $query = $narrow($this->rows($filter, $level))
            ->groupBy(...$columns)
            ->select($columns)
            ->selectRaw($metrics, $bindings)
            ->orderByRaw($this->peopleOrder($level).' DESC', []);

        foreach ($columns as $column) {
            $query->orderBy($column);
        }

        $read = $query
            ->limit(self::MOST_GROUPS)
            ->toBase()
            ->get()
            ->map(fn (object $row): array => ['keys' => self::keys((array) $row, $columns), ...self::counted((array) $row)])
            ->values()
            ->all();

        return $this->maturing($read, $filter, $level, $columns, $narrow);
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

        $level = $this->level($filter, $columns);

        return $this->rows($filter, $level)
            ->whereIn($columns[0], $firstValues)
            ->whereNotNull($columns[1])
            ->groupBy(...$columns)
            ->select($columns)
            ->selectRaw($this->peopleOrder($level).' AS visitors', [])
            ->orderByRaw($this->peopleOrder($level).' DESC', [])
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

        foreach ($this->model()::query()
            ->whereBetween('network_day', [$fromDay, $toDay])
            ->where('arrived_at', '>=', $window->arrivedFrom())
            ->where('arrived_at', '<', $window->arrivedBefore())
            ->when($this->gate->reads(), fn (Builder $opened) => $opened->where('opened', true), fn (Builder $opened) => $opened->whereNotNull('opened_at'))
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

    /**
     * The first network day of all a visit came on, Y-m-d; none before the
     * first visit. A network day is at most 12 hours behind its arrival and
     * 14 ahead, so no visit arriving 26 hours or more after the first can
     * have an earlier one: only the arrivals of those hours are read, by the
     * arrival index.
     */
    public function firstDay(): ?string
    {
        $first = $this->recordedSince();

        if ($first === null) {
            return null;
        }

        $day = TrafficVisit::query()->where('arrived_at', '<', $first->addHours(26))->min('network_day');

        return $day === null ? null : substr((string) $day, 0, 10);
    }

    /** @return Builder<TrafficVisit>|Builder<AnalyticsVisit> */
    private function visits(AnalyticsFilter $filter, bool $openedOnly = true): Builder
    {
        $query = $this->model()::query()
            ->whereBetween('network_day', [$filter->fromDay(), $filter->toDay()])
            ->where('arrived_at', '>=', $filter->arrivedFrom())
            ->where('arrived_at', '<', $filter->arrivedBefore())
            ->when($openedOnly && $this->gate->reads(), fn (Builder $opened) => $opened->where('opened', true))
            ->when($openedOnly && ! $this->gate->reads(), fn (Builder $opened) => $opened->whereNotNull('opened_at'));

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

    /** @return Builder<TrafficVisit>|Builder<AnalyticsVisit> the ids of the visits the filter admits */
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
    private function keeping(AnalyticsFilter $filter, ?AnalyticsCellLevel $level, array $columns, array $read, array $rows, array $keep, int $limit, string $unnamed): array
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
            [$metrics, $bindings] = $this->metrics($filter, $level);

            // Past every group read, so after those found among them: the most people first still.
            array_push($found, ...self::ofGroups($this->rows($filter, $level), $columns, $unread)
                ->groupBy(...$columns)
                ->select($columns)
                ->selectRaw($metrics, $bindings)
                ->orderByRaw($this->peopleOrder($level).' DESC', [])
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
     * The rest of a split (by()): the visits of none of the groups listed —
     * and, while the visits of no value are counted apart or not at all,
     * none of those either.
     *
     * @param  Builder<covariant Model>  $query
     * @param  list<string>  $columns
     * @param  list<array<string, int|string|null>>  $listed
     * @return Builder<covariant Model>
     */
    private static function restOf(Builder $query, array $columns, array $listed, string $unnamed): Builder
    {
        if (in_array($unnamed, [self::UNNAMED_APART, self::UNNAMED_LEFT_OUT], true)) {
            $query->whereNotNull($columns[0]);
        }

        return self::excluding($query, $columns, $listed);
    }

    /**
     * The visits of any of the groups given: a visit whose columns all hold
     * a group's values — a null value by being null.
     *
     * @param  Builder<covariant Model>  $query
     * @param  list<string>  $columns
     * @param  list<array<string, int|string|null>>  $groups
     * @return Builder<covariant Model>
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
     * @param  Builder<covariant Model>  $query
     * @param  list<string>  $columns
     * @param  list<array<string, int|string|null>>  $groups
     * @return Builder<covariant Model>
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
    private function metrics(AnalyticsFilter $filter, ?AnalyticsCellLevel $level = null): array
    {
        if ($level !== null) {
            return [self::cellMetrics(), []];
        }

        $person = $this->person();
        $people = fn (string $test): string => 'COUNT(DISTINCT CASE WHEN '.$test.' THEN '.$person.' END)';
        $parts = [
            ['COUNT(DISTINCT '.$person.') AS visitors', []],
            [$people('NOT known').' AS new_visitors', []],
            [$people($this->test('started')).' AS starters', []],
            [$people($this->test('completed')).' AS completers', []],
            [$people($this->test('goal')).' AS goal', []],
            [$people($this->test('signup_form')).' AS signup_form', []],
            [$people($this->test('signed_up')).' AS signups', []],
            [$people($this->test('account')).' AS accounts', []],
            [$people($this->test('activated')).' AS activated', []],
            [$people($this->test('matured')).' AS matured_accounts', [ActivityDay::maturedBefore($filter->period->today, (int) config('analytics.activation_days'))]],
            ['SUM(votes) AS votes', []],
            [$people('votes > ?').' AS voters', [0]],
            ['SUM(comments) AS comments', []],
            [$people('comments > ?').' AS commenters', [0]],
            ['SUM(posts_created) AS posts', []],
            ['SUM(spend) AS spend', []],
            ['SUM(CASE WHEN spend_estimated THEN spend ELSE 0 END) AS spend_estimated', []],
            ['COUNT(*) AS visits', []],
            ['SUM(CASE WHEN '.$this->test('tagged').' THEN 1 ELSE 0 END) AS tagged', []],
        ];

        return [implode(', ', array_column($parts, 0)), array_merge(...array_column($parts, 1))];
    }

    /**
     * The level of the cells a read of the filter, split by the columns
     * given, takes its people from: the coarsest that holds every column it
     * filters or splits by, over whole days, where the gate allows the cells
     * at all; none when the read takes the rows (or the visits).
     *
     * @param  list<string>  $splitBy
     */
    private function level(AnalyticsFilter $filter, array $splitBy = []): ?AnalyticsCellLevel
    {
        if (! $this->gate->readsCells() || ! $filter->countsWholeDays()) {
            return null;
        }

        return AnalyticsCellLevel::covering(array_values(array_unique([...array_column($filter->conditions(), 0), ...$splitBy])));
    }

    /** @return Builder<TrafficVisit>|Builder<AnalyticsVisit>|Builder<AnalyticsCell> what a read counts over: the cells of its level, or the visits */
    private function rows(AnalyticsFilter $filter, ?AnalyticsCellLevel $level): Builder
    {
        return $level === null ? $this->visits($filter) : self::ofCells(AnalyticsCell::query(), $filter, $level);
    }

    /** @return Builder<AnalyticsCellAccount> the accounts of the cells a read counts over, those whose days to activate are over by today */
    private function accountsOf(AnalyticsFilter $filter, AnalyticsCellLevel $level): Builder
    {
        $before = ActivityDay::maturedBefore($filter->period->today, (int) config('analytics.activation_days'));

        return self::ofCells(AnalyticsCellAccount::query(), $filter, $level)->where('signup_day', '<', $before->format('Y-m-d'));
    }

    /**
     * @template TModel of AnalyticsCell|AnalyticsCellAccount
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel> the cells of a level on the filter's days whose columns hold the filter's values
     */
    private static function ofCells(Builder $query, AnalyticsFilter $filter, AnalyticsCellLevel $level): Builder
    {
        $query->where('level', $level->value)->whereBetween('network_day', [$filter->fromDay(), $filter->toDay()]);

        foreach ($filter->conditions() as [$column, $value]) {
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }

        return $query;
    }

    /** @param  Builder<covariant Model>  $accounts cell accounts (accountsOf()), narrowed or not: their people, each once */
    private function matured(Builder $accounts): int
    {
        return (int) ($accounts->selectRaw('COALESCE(rb_cardinality(rb_or_agg(accounts::roaringbitmap)), 0) AS matured', [])->toBase()->value('matured') ?? 0);
    }

    /**
     * @param  Builder<covariant Model>  $accounts  cell accounts (accountsOf())
     * @param  list<string>  $columns
     * @return array<string, int> the people among accounts of each group, by its key (groupKey())
     */
    private function maturedBy(Builder $accounts, array $columns): array
    {
        $matured = [];

        foreach ($accounts->groupBy(...$columns)->select($columns)->selectRaw('COALESCE(rb_cardinality(rb_or_agg(accounts::roaringbitmap)), 0) AS matured', [])->toBase()->get() as $row) {
            $matured[self::groupKey(self::keys((array) $row, $columns))] = (int) $row->matured;
        }

        return $matured;
    }

    /**
     * The groups of a split read from the cells with their matured accounts:
     * the people among the accounts kept by sign-up day whose days to
     * activate are over, of the same groups, conditions and narrowing. The
     * rows' groups as they are when the split reads the visits.
     *
     * @param  list<array{keys: array<string, int|string|null>, figures: VisitorFigures, visits: int, tagged: int}>  $rows
     * @param  list<string>  $columns
     * @param  (Closure(Builder<covariant Model>): Builder<covariant Model>)|null  $narrow  what else the split read narrows the cells by
     * @return list<array{keys: array<string, int|string|null>, figures: VisitorFigures, visits: int, tagged: int}>
     */
    private function maturing(array $rows, AnalyticsFilter $filter, ?AnalyticsCellLevel $level, array $columns, ?Closure $narrow = null): array
    {
        if ($level === null || $rows === []) {
            return $rows;
        }

        $accounts = $this->accountsOf($filter, $level);
        $matured = $this->maturedBy($narrow === null ? $accounts : $narrow($accounts), $columns);

        foreach ($rows as $index => $row) {
            $rows[$index]['figures'] = $row['figures']->withMaturedAccounts($matured[self::groupKey($row['keys'])] ?? 0);
        }

        return $rows;
    }

    /** @param  array<string, int|string|null>  $keys a group's values, as one key */
    private static function groupKey(array $keys): string
    {
        return (string) json_encode(array_values($keys));
    }

    /** What a split orders its groups by: their people, counted on the rows or as the union of the cells' sets. */
    private function peopleOrder(?AnalyticsCellLevel $level): string
    {
        return $level === null ? 'COUNT(DISTINCT '.$this->person().')' : 'COALESCE(rb_cardinality(rb_or_agg(visitors::roaringbitmap)), 0)';
    }

    /** The figures of a row as SQL over cells: each step's people the size of the union of the cells' sets, the sums summed. */
    private static function cellMetrics(): string
    {
        $parts = [];

        foreach (['visitors', 'new_visitors', 'starters', 'completers', 'goal', 'signup_form', 'signups', 'accounts', 'activated', 'voters', 'commenters'] as $people) {
            $parts[] = 'COALESCE(rb_cardinality(rb_or_agg('.$people.'::roaringbitmap)), 0) AS '.$people;
        }

        foreach (['votes', 'comments', 'posts', 'spend', 'spend_estimated', 'visits', 'tagged'] as $sum) {
            $parts[] = 'COALESCE(SUM('.$sum.'), 0) AS '.$sum;
        }

        return implode(', ', $parts);
    }

    /**
     * The model the visits are read from: Analytics' own rows of them once they are filled and up to date, else the visits.
     *
     * @return class-string<TrafficVisit>|class-string<AnalyticsVisit>
     */
    private function model(): string
    {
        return $this->gate->reads() ? AnalyticsVisit::class : TrafficVisit::class;
    }

    /** The column a browser is counted by: its number on Analytics' own rows, its hash on the visits. */
    private function person(): string
    {
        return $this->gate->reads() ? 'visitor_key' : 'visitor_hash';
    }

    /** A test of a visit (TESTS), as SQL on the rows read. */
    private function test(string $name): string
    {
        return self::TESTS[$name][$this->gate->reads() ? 1 : 0];
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
