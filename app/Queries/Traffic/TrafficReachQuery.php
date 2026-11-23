<?php

namespace App\Queries\Traffic;

use App\Contracts\Persistence\RawSqlPersistenceBoundary;
use App\Enums\TrafficConnectionStatus;
use App\Enums\TrafficSpendOrigin;
use App\Models\TrafficApiConnection;
use App\Models\TrafficSource;
use App\Models\TrafficSpendDay;
use App\Models\TrafficSpendDayCountry;
use App\Models\TrafficVisit;
use App\Support\Traffic\Money\TrafficMoney;
use App\Support\Traffic\Reports\ReachFigures;
use App\Support\Traffic\Reports\ReachTally;
use App\Support\Traffic\Reports\TrafficReportFilter;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Iterator;

/**
 * How many of the impressions a network billed reached RateGuru
 * (docs/architecture/traffic.md, "Reach"): the network's own figures of a
 * period by day, campaign and site or zone — only those carrying
 * impressions and no clicks, none reported or none counted, where an
 * impression is the visit the network sells — from one origin a source's
 * day, a file before the API as a day's spend is counted; and the visits of
 * the same slices, matched much as spend is shared out, the narrowest figure
 * first, each visit once — though of figures equally narrow the one naming
 * its campaign, then its site, goes first, where spend goes by figure id.
 * Visits of a slice the network did not report count on neither side. Two
 * grouped reads with every value bound, streamed in order of source and day
 * and matched one source's day at a time, so a long period is never held
 * whole: what the report shows is folded as it goes (ReachTally).
 */
final class TrafficReachQuery implements RawSqlPersistenceBoundary
{
    private const array ORDER = [TrafficSpendOrigin::Manual, TrafficSpendOrigin::Csv, TrafficSpendOrigin::Api, TrafficSpendOrigin::Fixed];

    /**
     * The period's slices folded into what the report shows: of every
     * source, or of one; of every country, or of one.
     */
    public function tally(string $fromDay, string $toDay, ?int $sourceId = null, ?CarbonImmutable $now = null, ?string $country = null): ReachTally
    {
        $tally = new ReachTally;

        foreach ($this->slices($fromDay, $toDay, $sourceId, null, $now, $country) as $slice) {
            $tally->add($slice);
        }

        return $tally;
    }

    /**
     * The countries the API reported impressions in over the period, with
     * how many, the most first.
     *
     * @return array<string, int>
     */
    public function countries(string $fromDay, string $toDay, ?int $sourceId = null, ?int $campaignId = null): array
    {
        return TrafficSpendDayCountry::query()
            ->whereBetween('network_day', [$fromDay, $toDay])
            ->when($sourceId !== null, fn (Builder $query) => $query->where('source_id', $sourceId))
            ->when($campaignId !== null, fn (Builder $query) => $query->where('campaign_id', $campaignId))
            ->groupBy('country')
            ->select('country')
            ->selectRaw('SUM(impressions) AS impressions', [])
            ->orderByDesc('impressions')
            ->orderBy('country')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->country => (int) $row->impressions])
            ->all();
    }

    /**
     * Each slice the network reported impressions for, one source's day
     * after another, with the visits it matched, whether its day is closed —
     * over for the source, and for figures from the API, closed by the sync
     * too while the source is connected — and whether its figure is one the
     * visits' spend was shared out from. Of one source and one campaign when
     * given; a day's origin is chosen, and its visits matched, over all its
     * campaigns, so a campaign's slices are the same ones the whole source
     * counts. Of one country when given: the API's figures for it, against
     * the visits located there.
     *
     * @return Generator<int, array{source_id: int, day: string, campaign_id: int|null, site_ref: string, zone_ref: string, origin: string, closed: bool, counted: bool, figures: ReachFigures}>
     */
    public function slices(string $fromDay, string $toDay, ?int $sourceId = null, ?int $campaignId = null, ?CarbonImmutable $now = null, ?string $country = null): Generator
    {
        $figures = $country === null ? $this->figures($fromDay, $toDay, $sourceId) : $this->countryFigures($fromDay, $toDay, $sourceId, $country);
        $sourceIds = $figures->distinct()->pluck('source_id')->map(fn (mixed $id): int => (int) $id)->values()->all();

        if ($sourceIds === []) {
            return;
        }

        $closed = $this->closedBy($sourceIds, $now ?? CarbonImmutable::now());
        $visits = $this->visits($fromDay, $toDay, $sourceIds, $country);
        $day = null;
        $rows = [];

        foreach ($country === null ? $this->facts($fromDay, $toDay, $sourceId) : $this->countryFacts($fromDay, $toDay, $sourceId, $country) as $row) {
            $at = [(int) $row->source_id, (string) $row->network_day];

            if ($day !== null && $at !== $day) {
                foreach ($this->matched($day, $rows, $visits, $closed, $campaignId) as $slice) {
                    yield $slice;
                }

                $rows = [];
            }

            $day = $at;
            $rows[] = $row;
        }

        if ($day !== null) {
            foreach ($this->matched($day, $rows, $visits, $closed, $campaignId) as $slice) {
                yield $slice;
            }
        }
    }

    /**
     * One source's day: its figures from the first origin that has them;
     * the visits of the day, read on up to it; and each figure, the
     * narrowest first — of figures equally narrow, one naming its campaign,
     * then its site, before one for any — and then in one order, so each
     * visit goes to the same figure on every reading, with the visits of its
     * slice no narrower or more specific one took. A visit group is looked up
     * only under the slices the day's figures name.
     *
     * @param  array{0: int, 1: string}  $day
     * @param  list<object>  $rows
     * @param  Iterator<int, object>  $visits
     * @param  callable(int, string, string): bool  $closed
     * @return list<array{source_id: int, day: string, campaign_id: int|null, site_ref: string, zone_ref: string, origin: string, closed: bool, counted: bool, figures: ReachFigures}>
     */
    private function matched(array $day, array $rows, Iterator $visits, callable $closed, ?int $campaignId): array
    {
        $origins = array_map(fn (object $row): string => (string) $row->origin, $rows);
        $origin = (string) collect(self::ORDER)->first(fn (TrafficSpendOrigin $candidate): bool => in_array($candidate->value, $origins, true))?->value;
        $facts = [];

        foreach ($rows as $row) {
            if ((string) $row->origin !== $origin) {
                continue;
            }

            $campaign = $row->campaign_id === null ? null : (int) $row->campaign_id;
            $slice = self::slice($campaign === null ? '*' : (string) $campaign, (string) $row->site_ref, (string) $row->zone_ref);
            $facts[$slice] ??= ['slice' => $slice, 'campaign_id' => $campaign, 'site_ref' => (string) $row->site_ref, 'zone_ref' => (string) $row->zone_ref, 'impressions' => 0, 'amount' => BigDecimal::zero(), 'counted' => false];
            $facts[$slice]['impressions'] += (int) $row->impressions;
            $facts[$slice]['amount'] = $facts[$slice]['amount']->plus(TrafficMoney::of($row->amount));
            $facts[$slice]['counted'] = $facts[$slice]['counted'] || (bool) $row->counted;
        }

        $groups = [];
        $index = [];

        for (; $visits->valid(); $visits->next()) {
            $group = $visits->current();
            $order = [(int) $group->source_id, (string) $group->network_day] <=> $day;

            if ($order > 0) {
                break;
            }

            if ($order < 0) {
                continue;
            }

            $groups[] = [(int) $group->visits, (int) $group->opened, (int) $group->started];
            // A visit with no campaign is reached only by a figure for any campaign; one with no site or zone, likewise.
            $campaign = $group->campaign_id === null ? '-' : (string) (int) $group->campaign_id;

            foreach ([$campaign, '*'] as $c) {
                foreach ([(string) $group->site_ref, '*'] as $s) {
                    foreach ([(string) $group->zone_ref, '*'] as $z) {
                        if (isset($facts[$key = $c."\x1F".$s."\x1F".$z])) {
                            $index[$key][] = count($groups) - 1;
                        }
                    }
                }
            }
        }

        $facts = array_values($facts);
        // Of equal breadth, a figure naming its campaign, then its site, goes before one for any: a zone's figure of
        // campaign 7 takes campaign 7's visits of the zone before the zone's figure of every campaign does.
        usort($facts, fn (array $a, array $b): int => [self::breadth($a), ...self::wildcards($a), $a['campaign_id'] ?? 0, $a['site_ref'], $a['zone_ref']]
            <=> [self::breadth($b), ...self::wildcards($b), $b['campaign_id'] ?? 0, $b['site_ref'], $b['zone_ref']]);
        $taken = [];
        $slices = [];

        foreach ($facts as $fact) {
            [$reached, $opened, $started] = [0, 0, 0];

            foreach ($index[$fact['slice']] ?? [] as $group) {
                if (isset($taken[$group])) {
                    continue;
                }

                $taken[$group] = true;
                $reached += $groups[$group][0];
                $opened += $groups[$group][1];
                $started += $groups[$group][2];
            }

            // Every figure takes its visits first: a campaign asked for keeps those the others left it.
            if ($campaignId !== null && $fact['campaign_id'] !== $campaignId) {
                continue;
            }

            $slices[] = [
                'source_id' => $day[0],
                'day' => $day[1],
                'campaign_id' => $fact['campaign_id'],
                'site_ref' => $fact['site_ref'],
                'zone_ref' => $fact['zone_ref'],
                'origin' => $origin,
                'closed' => $closed($day[0], $day[1], $origin),
                'counted' => $fact['counted'],
                'figures' => new ReachFigures($fact['impressions'], TrafficMoney::stored($fact['amount']), $reached, $opened, $started),
            ];
        }

        return $slices;
    }

    /**
     * The network's figures carrying impressions and no clicks — none
     * reported, or none counted — of the period, and of one source when
     * given.
     *
     * @return Builder<TrafficSpendDay>
     */
    private function figures(string $fromDay, string $toDay, ?int $sourceId): Builder
    {
        return TrafficSpendDay::query()
            ->whereBetween('network_day', [$fromDay, $toDay])
            ->whereNotNull('reported_impressions')
            ->where(fn (Builder $query) => $query->whereNull('reported_clicks')->orWhere('reported_clicks', 0))
            ->when($sourceId !== null, fn (Builder $query) => $query->where('source_id', $sourceId));
    }

    /**
     * Those figures by source, day, origin and slice, and whether the spend
     * was shared out from them, in order of source and day.
     *
     * @return iterable<int, object>
     */
    private function facts(string $fromDay, string $toDay, ?int $sourceId): iterable
    {
        return $this->figures($fromDay, $toDay, $sourceId)
            ->groupBy('source_id', 'network_day', 'origin', 'campaign_id', 'site_ref', 'zone_ref', 'counted')
            ->select('source_id', 'network_day', 'origin', 'campaign_id', 'site_ref', 'zone_ref', 'counted')
            ->selectRaw('SUM(reported_impressions) AS impressions, SUM(amount) AS amount', [])
            ->orderBy('source_id')
            ->orderBy('network_day')
            ->toBase()
            ->cursor();
    }

    /**
     * One country's figures of the period, and of one source when given. Only
     * the API reports countries, so a day a file or a typed total counts from
     * has none here.
     *
     * @return Builder<TrafficSpendDayCountry>
     */
    private function countryFigures(string $fromDay, string $toDay, ?int $sourceId, string $country): Builder
    {
        $table = (new TrafficSpendDayCountry)->getTable();
        $figures = (new TrafficSpendDay)->getTable();

        return TrafficSpendDayCountry::query()
            ->whereBetween('network_day', [$fromDay, $toDay])
            ->where('country', $country)
            ->when($sourceId !== null, fn (Builder $query) => $query->where('source_id', $sourceId))
            ->whereNotExists(fn ($before) => $before->from($figures)
                ->whereColumn("{$figures}.source_id", "{$table}.source_id")
                ->whereColumn("{$figures}.network_day", "{$table}.network_day")
                ->whereIn("{$figures}.origin", [TrafficSpendOrigin::Manual->value, TrafficSpendOrigin::Csv->value])
                ->whereNotNull("{$figures}.reported_impressions")
                ->where(fn ($noClicks) => $noClicks->whereNull("{$figures}.reported_clicks")->orWhere("{$figures}.reported_clicks", 0)));
    }

    /**
     * Those figures, shaped as facts() reads them, in order of source and
     * day. Never a figure spend was shared out from: the hint that asks is
     * judged over every country.
     *
     * @return Generator<int, object>
     */
    private function countryFacts(string $fromDay, string $toDay, ?int $sourceId, string $country): Generator
    {
        $rows = $this->countryFigures($fromDay, $toDay, $sourceId, $country)
            ->select('source_id', 'network_day', 'campaign_id', 'site_ref', 'impressions', 'amount')
            ->orderBy('source_id')
            ->orderBy('network_day')
            ->toBase()
            ->cursor();

        foreach ($rows as $row) {
            $row->origin = TrafficSpendOrigin::Api->value;
            $row->zone_ref = '';
            $row->counted = false;

            yield $row;
        }
    }

    /**
     * The visits of the sources' days by campaign, site and zone, with how
     * many opened the page and started the game, in order of source and day:
     * those located in one country when given.
     *
     * @param  list<int>  $sourceIds
     * @return Iterator<int, object>
     */
    private function visits(string $fromDay, string $toDay, array $sourceIds, ?string $country = null): Iterator
    {
        $window = new TrafficReportFilter($fromDay, $toDay);

        return TrafficVisit::query()
            ->tracked()
            ->whereIn('source_id', $sourceIds)
            ->when($country !== null, fn (Builder $query) => $query->where('country', $country))
            ->whereBetween('network_day', [$fromDay, $toDay])
            ->where('arrived_at', '>=', $window->arrivedFrom())
            ->where('arrived_at', '<', $window->arrivedBefore())
            ->groupBy('source_id', 'network_day', 'campaign_id', 'site_ref', 'zone_ref')
            ->select('source_id', 'network_day', 'campaign_id', 'site_ref', 'zone_ref')
            ->selectRaw('COUNT(*) AS visits, SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened, SUM(CASE WHEN started_at IS NOT NULL THEN 1 ELSE 0 END) AS started', [])
            ->orderBy('source_id')
            ->orderBy('network_day')
            ->toBase()
            ->cursor()
            ->getIterator();
    }

    /**
     * Whether a source's day is closed: over in its reporting time zone, and
     * from the API, closed by the sync too while the source is connected —
     * a source no longer connected gets no newer figures.
     *
     * @param  list<int>  $sourceIds
     * @return callable(int, string, string): bool
     */
    private function closedBy(array $sourceIds, CarbonImmutable $now): callable
    {
        $today = TrafficSource::query()->whereKey($sourceIds)->get(['id', 'reporting_timezone'])
            ->mapWithKeys(fn (TrafficSource $source): array => [(int) $source->id => $now->setTimezone($source->reporting_timezone ?: 'UTC')->toDateString()])
            ->all();
        $connections = TrafficApiConnection::query()->whereIn('source_id', $sourceIds)->get(['source_id', 'status', 'spend_closed_through'])->keyBy('source_id');

        return function (int $sourceId, string $day, string $origin) use ($today, $connections): bool {
            $connection = $connections->get($sourceId);

            return $day < ($today[$sourceId] ?? $day)
                && ($origin !== TrafficSpendOrigin::Api->value || $connection === null || $connection->status === TrafficConnectionStatus::Disconnected
                    || ($connection->spend_closed_through !== null && $day <= $connection->spend_closed_through));
        };
    }

    /** The key a figure's slice is looked up by: its campaign, site and zone, "*" for any. */
    private static function slice(string $campaign, string $siteRef, string $zoneRef): string
    {
        return $campaign."\x1F".($siteRef === '' ? '*' : $siteRef)."\x1F".($zoneRef === '' ? '*' : $zoneRef);
    }

    /**
     * 0 for a zone's figure, 1 a site's, 2 a campaign's, 3 the whole source's.
     *
     * @param  array{campaign_id: int|null, site_ref: string, zone_ref: string}  $fact
     */
    private static function breadth(array $fact): int
    {
        return match (true) {
            $fact['zone_ref'] !== '' => 0,
            $fact['site_ref'] !== '' => 1,
            $fact['campaign_id'] !== null => 2,
            default => 3,
        };
    }

    /**
     * Whether a figure is for any campaign, and for any site: 1 where it is,
     * so of figures equally broad the ones naming theirs go first.
     *
     * @param  array{campaign_id: int|null, site_ref: string}  $fact
     * @return array{0: int, 1: int}
     */
    private static function wildcards(array $fact): array
    {
        return [$fact['campaign_id'] === null ? 1 : 0, $fact['site_ref'] === '' ? 1 : 0];
    }
}
