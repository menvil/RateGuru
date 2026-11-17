<?php

namespace App\Queries\Traffic;

use App\Contracts\Persistence\RawSqlPersistenceBoundary;
use App\Enums\TrafficChannel;
use App\Enums\TrafficDimension;
use App\Models\TrafficCampaignStagePeriod;
use App\Models\TrafficVisit;
use App\Support\Traffic\Reports\ReportFigures;
use App\Support\Traffic\Reports\TrafficReportFilter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The figures of Growth → Traffic sources, read straight from the visits
 * (docs/architecture/traffic.md, "Reports"): conditional sums and counts
 * with every value bound and every column named by TrafficDimension, never
 * arithmetic on dates or a branch per engine. A level of the tree, the
 * sites and zones of the networks, the campaigns and their stages, and a
 * game's paid traffic — each one pass over the period's visits.
 */
final class TrafficReportQuery implements RawSqlPersistenceBoundary
{
    public const int MOST_GROUPS = 2000;

    /**
     * The whole period by device and type of traffic, with how the accounts
     * came: what the tiles, Mobile and desktop and How accounts come add up.
     *
     * @return list<array{device: string, channel: string, figures: ReportFigures, path: array<string, int>}>
     */
    public function summary(TrafficReportFilter $filter): array
    {
        [$metrics, $bindings] = self::metrics();
        $path = [
            'SUM(CASE WHEN signup_form_at IS NOT NULL THEN 1 ELSE 0 END) AS signup_form',
            'SUM(CASE WHEN signup_at IS NOT NULL THEN 1 ELSE 0 END) AS signup',
            'SUM(CASE WHEN login_at IS NOT NULL THEN 1 ELSE 0 END) AS login',
            'SUM(CASE WHEN late_signup_at IS NOT NULL THEN 1 ELSE 0 END) AS late',
            'SUM(CASE WHEN account_at IS NOT NULL AND signup_place = ? THEN 1 ELSE 0 END) AS place_game',
            'SUM(CASE WHEN account_at IS NOT NULL AND signup_place = ? THEN 1 ELSE 0 END) AS place_post',
            'SUM(CASE WHEN account_at IS NOT NULL AND signup_place = ? THEN 1 ELSE 0 END) AS place_feed',
            'SUM(CASE WHEN account_at IS NOT NULL AND signup_place = ? THEN 1 ELSE 0 END) AS place_other',
            'SUM(CASE WHEN account_at IS NOT NULL AND signup_after_seconds <= ? THEN 1 ELSE 0 END) AS within_10_minutes',
            'SUM(CASE WHEN account_at IS NOT NULL AND signup_after_seconds <= ? THEN 1 ELSE 0 END) AS within_an_hour',
            'SUM(CASE WHEN account_at IS NOT NULL AND signup_after_seconds <= ? THEN 1 ELSE 0 END) AS within_a_day',
        ];

        return $this->visits($filter)
            ->groupBy('device', 'channel')
            ->select('device', 'channel')
            ->selectRaw(implode(', ', [$metrics, ...$path]), [...$bindings, 'game', 'post', 'feed', 'other', 600, 3600, 86400])
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'device' => (string) $row->device,
                'channel' => (string) $row->channel,
                'figures' => ReportFigures::fromRow($row),
                'path' => array_map('intval', array_intersect_key((array) $row, array_flip(['signup_form', 'signup', 'login', 'late', 'place_game', 'place_post', 'place_feed', 'place_other', 'within_10_minutes', 'within_an_hour', 'within_a_day']))),
            ])
            ->all();
    }

    /**
     * One level of the tree under the path the filter carries, biggest first
     * — by accounts, then visits — at most 2 000 groups.
     *
     * @return list<array{keys: array<string, int|string|null>, figures: ReportFigures}>
     */
    public function level(TrafficReportFilter $filter, TrafficDimension $dimension): array
    {
        [$metrics, $bindings] = self::metrics();
        $columns = $dimension->columns();

        return $this->visits($filter)
            ->groupBy(...$columns)
            ->select(...$columns)
            ->selectRaw($metrics, $bindings)
            ->orderByRaw('SUM(CASE WHEN account_at IS NOT NULL THEN 1 ELSE 0 END) DESC, COUNT(*) DESC', [])
            ->orderBy($columns[0])
            ->limit(self::MOST_GROUPS)
            ->toBase()
            ->get()
            ->map(fn (object $row): array => ['keys' => array_intersect_key((array) $row, array_flip($columns)), 'figures' => ReportFigures::fromRow($row)])
            ->all();
    }

    /**
     * The networks' sites — or zones — with at least so many clicks: their
     * figures, and the campaign they came through when it was one.
     *
     * @param  list<int>|null  $campaignIds
     * @return list<array{source_id: int, site_ref: string|null, zone_ref: string|null, campaign_id: int|null, campaigns: int, figures: ReportFigures}>
     */
    public function zones(TrafficReportFilter $filter, bool $byZone, int $minVisits = 1, ?array $campaignIds = null): array
    {
        [$metrics, $bindings] = self::metrics();
        $columns = $byZone ? ['source_id', 'site_ref', 'zone_ref'] : ['source_id', 'site_ref'];

        return $this->visits($filter)
            ->where('channel', TrafficChannel::Network)
            ->when($campaignIds !== null, fn (Builder $query) => $query->whereIn('campaign_id', $campaignIds))
            ->groupBy(...$columns)
            ->select(...$columns)
            ->selectRaw('MIN(campaign_id) AS campaign_id, COUNT(DISTINCT campaign_id) AS campaigns, '.$metrics, $bindings)
            ->havingRaw('COUNT(*) >= ?', [$minVisits])
            ->orderBy('source_id')
            ->orderBy('site_ref')
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'source_id' => (int) $row->source_id,
                'site_ref' => $row->site_ref,
                'zone_ref' => $byZone ? $row->zone_ref : null,
                'campaign_id' => (int) $row->campaigns === 1 && $row->campaign_id !== null ? (int) $row->campaign_id : null,
                'campaigns' => (int) $row->campaigns,
                'figures' => ReportFigures::fromRow($row),
            ])
            ->all();
    }

    /**
     * The period's visits by the country group each was priced in.
     *
     * @return array<string, ReportFigures> by group key
     */
    public function byCountryGroup(TrafficReportFilter $filter): array
    {
        [$metrics, $bindings] = self::metrics();

        return $this->visits($filter)
            ->groupBy('country_group')
            ->select('country_group')
            ->selectRaw($metrics, $bindings)
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->country_group => ReportFigures::fromRow($row)])
            ->all();
    }

    /** @return array<int, ReportFigures> by campaign */
    public function byCampaign(TrafficReportFilter $filter): array
    {
        [$metrics, $bindings] = self::metrics();

        return $this->visits($filter)
            ->whereNotNull('campaign_id')
            ->groupBy('campaign_id')
            ->select('campaign_id')
            ->selectRaw($metrics, $bindings)
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->campaign_id => ReportFigures::fromRow($row)])
            ->all();
    }

    /**
     * Each stage a campaign went through, with the figures of its visits
     * while it lasted.
     *
     * @return list<array{stage: string, started_at: CarbonImmutable, ended_at: CarbonImmutable|null, figures: ReportFigures}>
     */
    public function stages(int $campaignId): array
    {
        [$metrics, $bindings] = self::metrics();

        return TrafficCampaignStagePeriod::query()->where('campaign_id', $campaignId)->orderBy('started_at')->get()
            ->map(fn (TrafficCampaignStagePeriod $period): array => [
                'stage' => $period->stage->value,
                'started_at' => $period->started_at,
                'ended_at' => $period->ended_at,
                'figures' => ReportFigures::fromRow((array) TrafficVisit::query()
                    ->where('campaign_id', $campaignId)
                    ->where('arrived_at', '>=', $period->started_at)
                    ->when($period->ended_at !== null, fn (Builder $query) => $query->where('arrived_at', '<', $period->ended_at))
                    ->selectRaw($metrics, $bindings)
                    ->toBase()
                    ->first()),
            ])
            ->all();
    }

    /**
     * A game's visits since a moment, by type, source, version and device,
     * with each group's first visit: the game's Traffic sources card and the
     * comparison of its versions on paid traffic, in one pass.
     *
     * @return list<array{channel: string, source_id: int|null, version_id: int|null, device: string, first_at: CarbonImmutable, figures: ReportFigures}>
     */
    public function gameBreakdown(int $gameId, CarbonImmutable $since): array
    {
        [$metrics, $bindings] = self::metrics();

        return TrafficVisit::query()
            ->where('game_id', $gameId)
            ->where('arrived_at', '>=', $since)
            ->groupBy('channel', 'source_id', 'version_id', 'device')
            ->select('channel', 'source_id', 'version_id', 'device')
            ->selectRaw('MIN(arrived_at) AS first_at, '.$metrics, $bindings)
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'channel' => (string) $row->channel,
                'source_id' => $row->source_id === null ? null : (int) $row->source_id,
                'version_id' => $row->version_id === null ? null : (int) $row->version_id,
                'device' => (string) $row->device,
                'first_at' => CarbonImmutable::parse((string) $row->first_at),
                'figures' => ReportFigures::fromRow($row),
            ])
            ->all();
    }

    /** @return Builder<TrafficVisit> */
    private function visits(TrafficReportFilter $filter): Builder
    {
        $query = TrafficVisit::query()
            ->whereBetween('network_day', [$filter->fromDay, $filter->toDay])
            ->where('arrived_at', '>=', $filter->arrivedFrom())
            ->where('arrived_at', '<', $filter->arrivedBefore());

        foreach ($filter->where as $column => $value) {
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }

        return $query;
    }

    /** @return array{0: string, 1: list<mixed>} the figures of a row, as SQL */
    private static function metrics(): array
    {
        return [implode(', ', [
            'COUNT(*) AS visits',
            'SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened',
            'SUM(CASE WHEN started_at IS NOT NULL THEN 1 ELSE 0 END) AS started',
            'SUM(CASE WHEN completed_at IS NOT NULL THEN 1 ELSE 0 END) AS completed',
            'SUM(CASE WHEN goal_at IS NOT NULL THEN 1 ELSE 0 END) AS goal',
            'SUM(CASE WHEN account_at IS NOT NULL THEN 1 ELSE 0 END) AS accounts',
            'SUM(CASE WHEN post_published_at IS NOT NULL THEN 1 ELSE 0 END) AS posted',
            'SUM(CASE WHEN returned_at IS NOT NULL THEN 1 ELSE 0 END) AS returned',
            'SUM(CASE WHEN known AND opened_at IS NOT NULL THEN 1 ELSE 0 END) AS known_opened',
            'SUM(spend) AS spend',
            'SUM(CASE WHEN spend_estimated THEN spend ELSE 0 END) AS spend_estimated',
            'SUM(CASE WHEN known THEN spend ELSE 0 END) AS known_spend',
            'SUM(value_real) AS value_real',
            'SUM(value_expected) AS value_expected',
        ]), []];
    }
}
