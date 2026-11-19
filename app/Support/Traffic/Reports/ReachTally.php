<?php

namespace App\Support\Traffic\Reports;

use App\Support\Traffic\Money\TrafficMoney;
use Brick\Math\BigDecimal;

/**
 * The networks' impressions of a period and the visits that reached
 * RateGuru (docs/architecture/traffic.md, "Reach"), folded slice by slice
 * as TrafficReachQuery reads them into only what the report shows — never a
 * list of every slice. By source and campaign: the sum, the days not closed
 * yet, and each site or zone — a zone where the network reported one, else
 * its site — over its closed days and over the others, with whether a closed
 * figure of it is the one its visits' spend was shared out from, and what
 * names it: its campaigns, and a zone's site. By source,
 * of every campaign: each day, and the closed days together. Asked of every
 * source or one, every campaign or one; amounts added up exactly, in
 * hundred-millionths of a dollar.
 */
final class ReachTally
{
    /** @var array<int, array<int|string, list<int>>> per source and campaign ('' for none): impressions, amount, reached, opened, started */
    private array $totals = [];

    /** @var array<int, array<int|string, array<string, true>>> per source and campaign: the days not closed yet */
    private array $openDays = [];

    /** @var array<int, array<int|string, array<string, list<int>>>> per source, campaign and site or zone: its closed days */
    private array $closedPlaces = [];

    /** @var array<int, array<int|string, array<string, list<int>>>> per source, campaign and site or zone: its days not closed yet */
    private array $openPlaces = [];

    /** @var array<int, array<int|string, array<string, true>>> per source and campaign: the sites and zones with a closed figure their visits' spend was shared out from */
    private array $inSpend = [];

    /** @var array<int, array<string, list<int>>> per source and day, of every campaign */
    private array $days = [];

    /** @var array<int, list<int>> per source: its closed days, of every campaign */
    private array $closedSources = [];

    /** @var array<string, string> each zone, keyed as its block, by the site the network first named with it */
    private array $zoneSites = [];

    /** @param  array{source_id: int, day: string, campaign_id: int|null, site_ref: string, zone_ref: string, closed: bool, counted: bool, figures: ReachFigures}  $slice */
    public function add(array $slice): void
    {
        $source = $slice['source_id'];
        $campaign = $slice['campaign_id'] ?? '';
        $day = $slice['day'];
        $figures = $slice['figures'];
        $add = [$figures->impressions, TrafficMoney::of($figures->amount)->getUnscaledValue()->toInt(), $figures->reached, $figures->opened, $figures->started];
        $place = match (true) {
            $slice['zone_ref'] !== '' => $source.':zone:'.$slice['zone_ref'],
            $slice['site_ref'] !== '' => $source.':site:'.$slice['site_ref'],
            default => null,
        };

        $this->totals[$source][$campaign] = self::plus($this->totals[$source][$campaign] ?? null, $add);
        $this->days[$source][$day] = self::plus($this->days[$source][$day] ?? null, $add);

        if ($slice['zone_ref'] !== '' && $slice['site_ref'] !== '') {
            $this->zoneSites[(string) $place] ??= $slice['site_ref'];
        }

        if ($slice['closed']) {
            $this->closedSources[$source] = self::plus($this->closedSources[$source] ?? null, $add);

            if ($place !== null) {
                $this->closedPlaces[$source][$campaign][$place] = self::plus($this->closedPlaces[$source][$campaign][$place] ?? null, $add);

                if ($slice['counted']) {
                    $this->inSpend[$source][$campaign][$place] = true;
                }
            }

            return;
        }

        $this->openDays[$source][$campaign][$day] = true;

        if ($place !== null) {
            $this->openPlaces[$source][$campaign][$place] = self::plus($this->openPlaces[$source][$campaign][$place] ?? null, $add);
        }
    }

    /** Every impression and visit of the sources and campaigns asked. */
    public function total(?int $sourceId = null, ?int $campaignId = null): ReachFigures
    {
        return self::figures(array_reduce(self::scoped($this->totals, $sourceId, $campaignId), self::plus(...)));
    }

    /**
     * The sources with impressions among those asked.
     *
     * @return list<int>
     */
    public function sources(?int $sourceId = null, ?int $campaignId = null): array
    {
        return array_values(array_filter(array_keys($this->totals), fn (int $source): bool => self::scoped($this->totals, $source, $campaignId) !== [] && ($sourceId === null || $source === $sourceId)));
    }

    /**
     * Each source's days not closed yet, in order.
     *
     * @return array<int, list<string>>
     */
    public function openDays(?int $sourceId = null, ?int $campaignId = null): array
    {
        $days = [];

        foreach ($this->openDays as $source => $byCampaign) {
            foreach (self::scoped([$source => $byCampaign], $sourceId, $campaignId) as $open) {
                $days[$source] = [...($days[$source] ?? []), ...array_keys($open)];
            }
        }

        return array_map(function (array $open): array {
            $open = array_values(array_unique($open));
            sort($open);

            return $open;
        }, $days);
    }

    /**
     * Each site or zone, keyed as its block — "source:site:ref",
     * "source:zone:ref" — over every day, or over its closed days only.
     *
     * @return array<string, ReachFigures>
     */
    public function places(?int $sourceId = null, ?int $campaignId = null, bool $closedOnly = false): array
    {
        $places = [];

        foreach ([...self::scoped($this->closedPlaces, $sourceId, $campaignId), ...($closedOnly ? [] : self::scoped($this->openPlaces, $sourceId, $campaignId))] as $byPlace) {
            foreach ($byPlace as $place => $add) {
                $places[$place] = self::plus($places[$place] ?? null, $add);
            }
        }

        return array_map(self::figures(...), $places);
    }

    /**
     * Each site or zone of the sources and campaigns asked, keyed as its
     * block, with the campaigns the network reported it under over every day
     * — in order, none for a figure of no campaign — and, for a zone, the
     * site the network named with it, when it did: what a place the visits
     * never came from is named by.
     *
     * @return array<string, array{campaigns: list<int>, site: string|null}>
     */
    public function placeNames(?int $sourceId = null, ?int $campaignId = null): array
    {
        $campaigns = [];

        foreach ([$this->closedPlaces, $this->openPlaces] as $bySource) {
            foreach ($sourceId === null ? $bySource : array_intersect_key($bySource, [$sourceId => true]) as $byCampaign) {
                foreach ($campaignId === null ? $byCampaign : array_intersect_key($byCampaign, [$campaignId => true]) as $campaign => $byPlace) {
                    foreach (array_keys($byPlace) as $place) {
                        $campaigns[$place] ??= [];

                        if ($campaign !== '') {
                            $campaigns[$place][(int) $campaign] = true;
                        }
                    }
                }
            }
        }

        $names = [];

        foreach ($campaigns as $place => $named) {
            $ids = array_keys($named);
            sort($ids);
            $names[$place] = ['campaigns' => $ids, 'site' => $this->zoneSites[$place] ?? null];
        }

        return $names;
    }

    /**
     * The sites and zones with a closed figure that their visits' spend was
     * shared out from, so their spend pays for the impressions that never
     * arrived as well.
     *
     * @return array<string, true>
     */
    public function placesInSpend(?int $sourceId = null, ?int $campaignId = null): array
    {
        return array_merge(...self::scoped($this->inSpend, $sourceId, $campaignId));
    }

    /**
     * Each source's closed days together, of every campaign.
     *
     * @return array<int, ReachFigures>
     */
    public function closedBySource(?int $sourceId = null): array
    {
        return array_map(self::figures(...), $sourceId === null ? $this->closedSources : array_intersect_key($this->closedSources, [$sourceId => true]));
    }

    /**
     * One source's days, each of every campaign.
     *
     * @return array<string, ReachFigures>
     */
    public function days(int $sourceId): array
    {
        return array_map(self::figures(...), $this->days[$sourceId] ?? []);
    }

    /**
     * What a map by source and campaign holds for those asked: one source or
     * every one, one campaign or every one — none standing for no campaign.
     *
     * @template T
     *
     * @param  array<int, array<int|string, T>>  $bySource
     * @return list<T>
     */
    private static function scoped(array $bySource, ?int $sourceId, ?int $campaignId): array
    {
        $found = [];

        foreach ($sourceId === null ? $bySource : array_intersect_key($bySource, [$sourceId => true]) as $byCampaign) {
            foreach ($campaignId === null ? $byCampaign : array_intersect_key($byCampaign, [$campaignId => true]) as $value) {
                $found[] = $value;
            }
        }

        return $found;
    }

    /**
     * @param  list<int>|null  $sum
     * @param  list<int>  $add
     * @return list<int>
     */
    private static function plus(?array $sum, array $add): array
    {
        return $sum === null ? $add : [$sum[0] + $add[0], $sum[1] + $add[1], $sum[2] + $add[2], $sum[3] + $add[3], $sum[4] + $add[4]];
    }

    /** @param  list<int>|null  $sum */
    private static function figures(?array $sum): ReachFigures
    {
        return $sum === null ? new ReachFigures : new ReachFigures($sum[0], (string) BigDecimal::ofUnscaledValue($sum[1], TrafficMoney::SCALE), $sum[2], $sum[3], $sum[4]);
    }
}
