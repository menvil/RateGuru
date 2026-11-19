<?php

namespace App\Queries\Analytics;

use App\Contracts\Persistence\RawSqlPersistenceBoundary;
use App\Models\TrafficCampaign;
use App\Models\TrafficSegment;
use App\Models\TrafficVisit;
use App\Support\Analytics\AnalyticsFilter;
use App\Support\Analytics\AnalyticsSource;
use App\Support\Analytics\SegmentRules;
use App\Support\Analytics\VisitorFigures;
use App\Support\Geo\Countries;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The segments of a paid source and the people each caught, for Analytics →
 * Campaigns: the clicks of the source in the period's network days, on the
 * device chosen or either, read straight from the visits like every Analytics
 * figure. Which segment a click is in is worked out once per click, each
 * segment's SQL bound once (SegmentRules::predicate()), and every row is then
 * a conditional distinct count of browsers over those flags: all the
 * source's people, each segment's, and those of no segment, in one read.
 * People count once their page ran; what the clicks cost counts every click,
 * its page run or not, as Traffic sources counts spend.
 *
 *  - of()           a source's segments, those deleted left out, oldest first;
 *  - figures()      the people of the source, of each segment and of none;
 *  - preview()      the people a draft's rules catch;
 *  - rules()        a draft read into rules, the campaigns it names looked
 *                   up among the source's own;
 *  - campaignRefs() campaigns' ids in the network, by campaign id.
 *
 * Read-only.
 *
 * @phpstan-import-type SegmentRule from SegmentRules
 */
final class TrafficSegmentsQuery implements RawSqlPersistenceBoundary
{
    /** @return Collection<int, TrafficSegment> */
    public function of(int $sourceId): Collection
    {
        return TrafficSegment::query()->where('source_id', $sourceId)->orderBy('created_at')->orderBy('id')->get();
    }

    /**
     * The people of the source, of each segment given and of none of them,
     * with what their clicks cost: a person in two segments counts in each,
     * so the segments can add up to more than the source. With no segment,
     * nobody is in none.
     *
     * @param  array<int, list<SegmentRule>>  $segments  each segment's rules, by its id
     * @return array{all: VisitorFigures, segments: array<int, VisitorFigures>, rest: VisitorFigures}
     */
    public function figures(int $sourceId, AnalyticsFilter $filter, array $segments): array
    {
        $flags = [];
        $flagBindings = [];
        $ids = array_keys($segments);

        foreach (array_values($segments) as $index => $rules) {
            [$sql, $bindings] = SegmentRules::predicate($rules);
            $flags[] = "CASE WHEN {$sql} THEN 1 ELSE 0 END AS segment_{$index}";
            array_push($flagBindings, ...$bindings);
        }

        $clicks = $this->clicks($sourceId, $filter)
            ->select(['visitor_hash', 'known', 'opened_at', 'started_at', 'account_at', 'activated_at', 'spend', 'spend_estimated'])
            ->when($flags !== [], fn (Builder $flagged) => $flagged->selectRaw(implode(', ', $flags), $flagBindings));

        $metrics = [self::metrics('all', '1 = 1')];

        foreach (array_keys($ids) as $index) {
            $metrics[] = self::metrics('s'.$index, "segment_{$index} = 1");
        }

        $metrics[] = self::metrics('rest', $ids === [] ? '1 = 0' : implode(' AND ', array_map(fn (int $index): string => "segment_{$index} = 0", array_keys($ids))));

        $row = (array) TrafficVisit::query()->fromSub($clicks, 'segment_clicks')->selectRaw(implode(', ', $metrics), [])->toBase()->first();
        $read = [];

        foreach ($ids as $index => $id) {
            $read[$id] = self::figuresOf($row, 's'.$index);
        }

        return ['all' => self::figuresOf($row, 'all'), 'segments' => $read, 'rest' => self::figuresOf($row, 'rest')];
    }

    /**
     * The people of the source a draft's rules catch, whose page ran: the
     * visitors, the new ones among them, and the accounts they made.
     *
     * @param  list<SegmentRule>  $rules
     */
    public function preview(int $sourceId, AnalyticsFilter $filter, array $rules): VisitorFigures
    {
        [$sql, $bindings] = SegmentRules::predicate($rules);

        return VisitorFigures::fromRow((array) $this->clicks($sourceId, $filter)
            ->whereNotNull('opened_at')
            ->whereRaw($sql, $bindings)
            ->selectRaw(implode(', ', [
                'COUNT(DISTINCT visitor_hash) AS visitors',
                'COUNT(DISTINCT CASE WHEN NOT known THEN visitor_hash END) AS new_visitors',
                'COUNT(DISTINCT CASE WHEN account_at IS NOT NULL THEN visitor_hash END) AS accounts',
            ]), [])
            ->toBase()
            ->first());
    }

    /**
     * A draft's conditions read into rules for a source, with what is wrong
     * with each (SegmentRules::read()): a campaign named by its id in the
     * network is the source's campaign of that id, and one the source hasn't
     * is wrong.
     *
     * @param  array<int|string, mixed>  $draft
     * @return array{rules: list<SegmentRule>, errors: array<int, string>}
     */
    public function rules(int $sourceId, string $sourceName, array $draft): array
    {
        $refs = SegmentRules::campaignRefs($draft);
        $campaigns = [];

        if ($refs !== []) {
            foreach (TrafficCampaign::query()->where('source_id', $sourceId)->whereIn('external_id', $refs)->get(['id', 'external_id']) as $campaign) {
                $campaigns[$campaign->external_id] = (int) $campaign->id;
            }
        }

        return SegmentRules::read($draft, array_keys(Countries::all()), $campaigns, $sourceName);
    }

    /**
     * Campaigns' ids in the network, by campaign id.
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    public function campaignRefs(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return TrafficCampaign::query()->whereKey($ids)->pluck('external_id', 'id')->mapWithKeys(fn (mixed $ref, int|string $id): array => [(int) $id => (string) $ref])->all();
    }

    /**
     * The source's clicks of the period's network days, read through a wider
     * span of arrivals so the arrival index narrows the scan first, on the
     * device chosen or either: every column named by the filter's dimensions.
     *
     * @return Builder<TrafficVisit>
     */
    private function clicks(int $sourceId, AnalyticsFilter $filter): Builder
    {
        $scope = new AnalyticsFilter($filter->period, $filter->device, AnalyticsSource::paid($sourceId));
        $query = TrafficVisit::query()
            ->whereBetween('network_day', [$scope->fromDay(), $scope->toDay()])
            ->where('arrived_at', '>=', $scope->arrivedFrom())
            ->where('arrived_at', '<', $scope->arrivedBefore());

        foreach ($scope->conditions() as [$column, $value]) {
            $query->where($column, $value);
        }

        return $query;
    }

    /**
     * One row's figures as SQL over the flagged clicks, each column named
     * with the row's prefix: different browsers whose page ran — all of them,
     * the new ones, those who started a game, made an account, activated it —
     * and what the row's clicks cost, how much of it estimated.
     */
    private static function metrics(string $prefix, string $when): string
    {
        $people = fn (string $also, string $as): string => "COUNT(DISTINCT CASE WHEN opened_at IS NOT NULL{$also} AND {$when} THEN visitor_hash END) AS {$prefix}_{$as}";

        return implode(', ', [
            $people('', 'visitors'),
            $people(' AND NOT known', 'new_visitors'),
            $people(' AND started_at IS NOT NULL', 'starters'),
            $people(' AND account_at IS NOT NULL', 'accounts'),
            $people(' AND activated_at IS NOT NULL', 'activated'),
            "SUM(CASE WHEN {$when} THEN spend ELSE 0 END) AS {$prefix}_spend",
            "SUM(CASE WHEN spend_estimated AND {$when} THEN spend ELSE 0 END) AS {$prefix}_spend_estimated",
        ]);
    }

    /** @param  array<string, mixed>  $row */
    private static function figuresOf(array $row, string $prefix): VisitorFigures
    {
        $own = [];

        foreach (['visitors', 'new_visitors', 'starters', 'accounts', 'activated', 'spend', 'spend_estimated'] as $column) {
            $own[$column] = $row[$prefix.'_'.$column] ?? 0;
        }

        return VisitorFigures::fromRow($own);
    }
}
