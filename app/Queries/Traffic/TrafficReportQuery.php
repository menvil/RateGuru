<?php

namespace App\Queries\Traffic;

use App\Contracts\Persistence\RawSqlPersistenceBoundary;
use App\Enums\TrafficChannel;
use App\Enums\TrafficDimension;
use App\Enums\TrafficKnownReason;
use App\Models\AnalyticsVisit;
use App\Models\TrafficCampaignStagePeriod;
use App\Models\TrafficVisit;
use App\Queries\Analytics\AnalyticsRollupGate;
use App\Queries\Users\UnconfirmedAccountsQuery;
use App\Support\Traffic\Reports\ReportFigures;
use App\Support\Traffic\Reports\SignupFigures;
use App\Support\Traffic\Reports\TrafficReportFilter;
use App\Support\Traffic\Reports\VisitFilter;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The figures of Growth → Traffic sources, read straight from the visits
 * (docs/architecture/traffic.md, "Reports"): conditional sums and counts
 * with every value bound and every column named by TrafficDimension, never
 * arithmetic on dates or a branch per engine. A level of the tree, the
 * sites and zones of the networks, the campaigns and their stages, the
 * sign-ups and whether they were confirmed, and a game's paid traffic,
 * sign-ups and countries — of one device or some countries when its
 * Analytics narrows them (VisitFilter) — each one pass over the period's
 * visits.
 *
 * Only the visits the tracker counts (TrafficVisit::scopeTracked()): paid
 * clicks and game visits — a visit to the rest of the site is Analytics',
 * and leaves every figure here as it was.
 *
 * A sign-up not confirmed is never confirmed once its account's window to
 * confirm has closed — the account is removed then, with everything it did
 * — and still waiting before; the moment the window closed for sign-ups is
 * bound, as UnconfirmedAccountsQuery::cutoff() gives it.
 */
final class TrafficReportQuery implements RawSqlPersistenceBoundary
{
    /** @var array<string, string> what a figure tests on a visit, as the same test on Analytics' own rows of the visits */
    private const array ROLLUP_TESTS = [
        'opened_at IS NOT NULL' => 'opened',
        'started_at IS NOT NULL' => 'started',
        'completed_at IS NOT NULL' => 'completed',
        'goal_at IS NOT NULL' => 'goal',
        'account_at IS NOT NULL' => 'account',
        'account_at IS NULL' => 'NOT account',
        'post_published_at IS NOT NULL' => 'post_published',
        'returned_at IS NOT NULL' => 'returned',
        'signup_at IS NOT NULL' => 'signed_up',
    ];

    public function __construct(private readonly AnalyticsRollupGate $gate) {}

    public const int MOST_GROUPS = 2000;

    /**
     * The whole period by device and type of traffic, with how the accounts
     * came and the sign-ups: what the tiles, Mobile and desktop, How
     * accounts come and the totals of Sign-ups and confirmation add up.
     *
     * @return list<array{device: string, channel: string, figures: ReportFigures, signups: SignupFigures, path: array<string, int>}>
     */
    public function summary(TrafficReportFilter $filter, ?CarbonInterface $unconfirmedBefore = null): array
    {
        [$metrics, $bindings] = self::metrics();
        [$signups, $signupBindings] = self::signupMetrics($unconfirmedBefore ?? UnconfirmedAccountsQuery::cutoff());
        $path = [
            'SUM(CASE WHEN signup_form_at IS NOT NULL THEN 1 ELSE 0 END) AS signup_form',
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
            ->selectRaw(implode(', ', [$metrics, $signups, ...$path]), [...$bindings, ...$signupBindings, 'game', 'post', 'feed', 'other', 600, 3600, 86400])
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'device' => (string) $row->device,
                'channel' => (string) $row->channel,
                'figures' => ReportFigures::fromRow($row),
                'signups' => SignupFigures::fromRow($row),
                'path' => array_map('intval', array_intersect_key((array) $row, array_flip(['signup_form', 'login', 'late', 'place_game', 'place_post', 'place_feed', 'place_other', 'within_10_minutes', 'within_an_hour', 'within_a_day']))),
            ])
            ->all();
    }

    /**
     * The sign-ups of the visits on the filters split by a dimension, the
     * most first — then the most confirmed — at most 2 000 groups, and only
     * groups with a sign-up.
     *
     * @return list<array{keys: array<string, int|string|null>, figures: SignupFigures}>
     */
    public function signups(TrafficReportFilter $filter, TrafficDimension $dimension, ?CarbonInterface $unconfirmedBefore = null): array
    {
        [$signups, $bindings] = self::signupMetrics($unconfirmedBefore ?? UnconfirmedAccountsQuery::cutoff());
        $columns = $dimension->columns();

        return $this->visits($filter)
            ->groupBy(...$columns)
            ->select(...$columns)
            ->selectRaw($signups, $bindings)
            ->havingRaw('SUM(CASE WHEN signup_at IS NOT NULL THEN 1 ELSE 0 END) > ?', [0])
            ->orderByRaw('SUM(CASE WHEN signup_at IS NOT NULL THEN 1 ELSE 0 END) DESC, SUM(CASE WHEN account_at IS NOT NULL THEN 1 ELSE 0 END) DESC', [])
            ->tap(fn (Builder $query) => self::byEveryColumn($query, $columns))
            ->limit(self::MOST_GROUPS)
            ->toBase()
            ->get()
            ->map(fn (object $row): array => ['keys' => array_intersect_key((array) $row, array_flip($columns)), 'figures' => SignupFigures::fromRow($row)])
            ->all();
    }

    /**
     * One level of the tree under the path the filter carries, biggest first
     * — by accounts, then visits — at most 2 000 groups, each with its first
     * click in the period.
     *
     * @return list<array{keys: array<string, int|string|null>, first_at: CarbonImmutable, figures: ReportFigures}>
     */
    public function level(TrafficReportFilter $filter, TrafficDimension $dimension): array
    {
        [$metrics, $bindings] = self::metrics();
        $columns = $dimension->columns();

        return $this->visits($filter)
            ->groupBy(...$columns)
            ->select(...$columns)
            ->selectRaw('MIN(arrived_at) AS first_at, '.$metrics, $bindings)
            ->orderByRaw('SUM(CASE WHEN account_at IS NOT NULL THEN 1 ELSE 0 END) DESC, COUNT(*) DESC', [])
            ->tap(fn (Builder $query) => self::byEveryColumn($query, $columns))
            ->limit(self::MOST_GROUPS)
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'keys' => array_intersect_key((array) $row, array_flip($columns)),
                'first_at' => CarbonImmutable::parse((string) $row->first_at),
                'figures' => ReportFigures::fromRow($row),
            ])
            ->all();
    }

    /**
     * The networks' sites — or zones — with at least so many clicks: their
     * figures, their first click in the period, and the campaign they came
     * through when it was one.
     *
     * @param  list<int>|null  $campaignIds
     * @return list<array{source_id: int, site_ref: string|null, zone_ref: string|null, campaign_id: int|null, campaigns: int, first_at: CarbonImmutable, figures: ReportFigures}>
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
            ->selectRaw('MIN(campaign_id) AS campaign_id, COUNT(DISTINCT campaign_id) AS campaigns, MIN(arrived_at) AS first_at, '.$metrics, $bindings)
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
                'first_at' => CarbonImmutable::parse((string) $row->first_at),
                'figures' => ReportFigures::fromRow($row),
            ])
            ->all();
    }

    /**
     * Each network site's and zone's first click in the period, keyed as its
     * block is — "source:site:ref", "source:zone:ref" — a site's the first of
     * all its zones': from when the block rule may call one losing, whatever
     * narrower view the report reads its figures in.
     *
     * @return array<string, CarbonImmutable>
     */
    public function firstClicks(TrafficReportFilter $filter): array
    {
        $first = [];
        $rows = $this->visits($filter)
            ->where('channel', TrafficChannel::Network)
            ->groupBy('source_id', 'site_ref', 'zone_ref')
            ->select('source_id', 'site_ref', 'zone_ref')
            ->selectRaw('MIN(arrived_at) AS first_at', [])
            ->toBase()
            ->get();

        // One column, one format: its moments compare as text, and only each key's earliest is parsed.
        foreach ($rows as $row) {
            foreach (['site' => $row->site_ref, 'zone' => $row->zone_ref] as $level => $ref) {
                $key = $row->source_id.':'.$level.':'.$ref;

                if ((string) $ref !== '' && (! isset($first[$key]) || strcmp((string) $row->first_at, $first[$key]) < 0)) {
                    $first[$key] = (string) $row->first_at;
                }
            }
        }

        return array_map(fn (string $at): CarbonImmutable => CarbonImmutable::parse($at), $first);
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
                    ->tracked()
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
     * A game's visits since a moment, and before another when given, by type,
     * source, version and device, with each group's first visit: the game's
     * Traffic sources card and the comparison of its versions on paid
     * traffic, in one pass.
     *
     * @return list<array{channel: string, source_id: int|null, version_id: int|null, device: string, first_at: CarbonImmutable, figures: ReportFigures}>
     */
    public function gameBreakdown(int $gameId, CarbonImmutable $since, ?CarbonImmutable $until = null, ?VisitFilter $visits = null): array
    {
        [$metrics, $bindings] = $this->ofGame(self::metrics());

        return $this->gameVisits($gameId)
            ->where('arrived_at', '>=', $since)
            ->when($until !== null, fn (Builder $query) => $query->where('arrived_at', '<', $until))
            ->when($visits !== null, fn (Builder $query) => $query->matching($visits))
            ->groupBy('channel', 'source_id', 'version_id', 'device')
            ->select('channel', 'source_id', 'version_id', 'device')
            ->selectRaw('MIN(arrived_at) AS first_at, '.$metrics, $bindings)
            // In one order whichever rows are read.
            ->orderBy('channel')
            ->orderBy('source_id')
            ->orderBy('version_id')
            ->orderBy('device')
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

    /**
     * How many campaigns brought a game's visits in a window, by source.
     *
     * @return array<int, int>
     */
    public function gameCampaigns(int $gameId, CarbonImmutable $since, CarbonImmutable $until, ?VisitFilter $visits = null): array
    {
        return $this->gameVisits($gameId)
            ->where('arrived_at', '>=', $since)
            ->where('arrived_at', '<', $until)
            ->when($visits !== null, fn (Builder $query) => $query->matching($visits))
            ->whereNotNull('campaign_id')
            ->distinct()
            ->get(['source_id', 'campaign_id'])
            ->countBy(fn (TrafficVisit|AnalyticsVisit $visit): int => (int) $visit->source_id)
            ->all();
    }

    /**
     * The sign-ups of a game's visits since a moment and before another — of
     * one version when one is named — and how many visits there were: the
     * sign-ups the game's own figures lose once an account that never
     * confirmed is removed.
     *
     * @return array{visits: int, figures: SignupFigures}
     */
    public function gameSignups(int $gameId, CarbonImmutable $since, CarbonImmutable $until, ?int $versionId = null, ?CarbonInterface $unconfirmedBefore = null, ?VisitFilter $visits = null): array
    {
        [$signups, $bindings] = $this->ofGame(self::signupMetrics($unconfirmedBefore ?? UnconfirmedAccountsQuery::cutoff()));
        $row = (array) $this->gameVisits($gameId)
            ->where('arrived_at', '>=', $since)
            ->where('arrived_at', '<', $until)
            ->when($versionId !== null, fn (Builder $query) => $query->where('version_id', $versionId))
            ->when($visits !== null, fn (Builder $query) => $query->matching($visits))
            // The anonymous visitors' view: a visitor signed in when they came is a logged-in player, not one of them.
            ->when(
                $this->gate->reads(),
                fn (Builder $query) => $query->where('signed_in', false),
                fn (Builder $query) => $query->where(fn (Builder $either) => $either->whereNull('known_reason')->orWhere('known_reason', '<>', TrafficKnownReason::SignedIn->value)),
            )
            ->selectRaw('COUNT(*) AS visits, '.$signups, $bindings)
            ->toBase()
            ->first();

        return ['visits' => (int) ($row['visits'] ?? 0), 'figures' => SignupFigures::fromRow($row)];
    }

    /**
     * The countries a game's recorded visits came from, as their codes in
     * order: what its Analytics can narrow its figures to. A visit whose
     * country is not known names none.
     *
     * @return list<string>
     */
    public function gameCountries(int $gameId): array
    {
        $country = TrafficDimension::Country->columns()[0];

        return $this->gameVisits($gameId)
            ->whereNotNull($country)
            ->distinct()
            ->orderBy($country)
            ->pluck($country)
            ->map(fn (mixed $code): string => (string) $code)
            ->values()
            ->all();
    }

    /**
     * The visits of a game the tracker counts: from Analytics' own rows of
     * them once AnalyticsRollupGate says they are filled and up to date, else
     * from the visits — the same rows either way.
     *
     * @return Builder<TrafficVisit>|Builder<AnalyticsVisit>
     */
    private function gameVisits(int $gameId): Builder
    {
        return ($this->gate->reads() ? AnalyticsVisit::query() : TrafficVisit::query())->tracked()->where('game_id', $gameId);
    }

    /**
     * Figures as SQL (metrics(), signupMetrics()) for the rows gameVisits()
     * reads: on Analytics' own rows each test of a moment is its flag.
     *
     * @param  array{0: string, 1: list<mixed>}  $figures
     * @return array{0: string, 1: list<mixed>}
     */
    private function ofGame(array $figures): array
    {
        return $this->gate->reads() ? [strtr($figures[0], self::ROLLUP_TESTS), $figures[1]] : $figures;
    }

    /** @return Builder<TrafficVisit> */
    private function visits(TrafficReportFilter $filter): Builder
    {
        $query = TrafficVisit::query()
            ->tracked()
            ->whereBetween('network_day', [$filter->fromDay, $filter->toDay])
            ->where('arrived_at', '>=', $filter->arrivedFrom())
            ->where('arrived_at', '<', $filter->arrivedBefore());

        foreach ($filter->where as $column => $value) {
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }

        return $query;
    }

    /**
     * Groups that tie ordered by every column they are grouped by, so they
     * keep one order from one reading to the next — and the same ones stay
     * within the limit.
     *
     * @param  Builder<TrafficVisit>  $query
     * @param  list<string>  $columns
     */
    private static function byEveryColumn(Builder $query, array $columns): void
    {
        foreach ($columns as $column) {
            $query->orderBy($column);
        }
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
            'SUM(CASE WHEN account_at IS NOT NULL AND NOT known THEN value_real ELSE 0 END) AS value_accounts',
            'SUM(value_expected) AS value_expected',
        ]), []];
    }

    /**
     * The sign-up figures of a row, as SQL: signed up, confirmed, never
     * confirmed — signed up at or before the moment the window closed — and
     * still waiting, then how long after signing up the confirmed ones
     * confirmed. That time is the seconds from the click to the confirmation
     * less those to the sign-up, compared by adding to the sign-up's side so
     * unsigned columns never go below zero.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private static function signupMetrics(CarbonInterface $unconfirmedBefore): array
    {
        return [implode(', ', [
            'SUM(CASE WHEN signup_at IS NOT NULL THEN 1 ELSE 0 END) AS signed_up',
            'SUM(CASE WHEN account_at IS NOT NULL THEN 1 ELSE 0 END) AS confirmed',
            'SUM(CASE WHEN signup_at IS NOT NULL AND account_at IS NULL AND signup_at <= ? THEN 1 ELSE 0 END) AS never_confirmed',
            'SUM(CASE WHEN signup_at IS NOT NULL AND account_at IS NULL AND signup_at > ? THEN 1 ELSE 0 END) AS waiting',
            'SUM(CASE WHEN account_at IS NOT NULL AND account_after_seconds <= signup_after_seconds + ? THEN 1 ELSE 0 END) AS confirmed_10_minutes',
            'SUM(CASE WHEN account_at IS NOT NULL AND account_after_seconds > signup_after_seconds + ? AND account_after_seconds <= signup_after_seconds + ? THEN 1 ELSE 0 END) AS confirmed_hour',
            'SUM(CASE WHEN account_at IS NOT NULL AND account_after_seconds > signup_after_seconds + ? AND account_after_seconds <= signup_after_seconds + ? THEN 1 ELSE 0 END) AS confirmed_day',
        ]), [$unconfirmedBefore, $unconfirmedBefore, 600, 600, 3600, 3600, 86400]];
    }
}
