<?php

namespace App\Filament\Pages\Experiments;

use App\Enums\ComparisonEvidence;
use App\Enums\GameAudience;
use App\Enums\PeriodDataState;
use App\Enums\PeriodMetric;
use App\Filament\Pages\ExperimentsPage;
use App\Filament\Resources\Games\GameResource;
use App\Filament\Resources\Games\Pages\GameAnalytics;
use App\Models\GameTrafficPeriod;
use App\Models\GameVersion;
use App\Queries\Games\ComparisonPeriodsQuery;
use App\Queries\Games\GameMetricsQuery;
use App\Queries\Games\TrafficPeriodScopesQuery;
use App\Support\Analytics\ActivityDay;
use App\Support\Analytics\ComparedVersion;
use App\Support\Analytics\PeriodComparison;
use App\Support\Analytics\PeriodVersionFunnel;
use Carbon\CarbonImmutable;

/**
 * What Growth → Experiments shows (ExperimentsPage): the list of traffic
 * periods — each with its game, audience and scope (a split's source and
 * campaign, or “Everyone else” beside one), days, shares, visitors and
 * whether its versions can be compared yet, all of a page's figures read at
 * once — or one period's page: its status, the periods of the same game,
 * audience and scope to switch to, what the figures say so far, every version's rates
 * against the reference version, and the ways into the game's Traffic,
 * Versions and Analytics tabs. Reads only; hiding a period is the page's,
 * through an Action.
 */
final class ExperimentsView
{
    /** A rate's difference from the reference's is coloured from this many percentage points, once the period has enough data. */
    private const float TONED_FROM = 0.3;

    /** @var array<string, string> the icon of each audience, as the reference draws it */
    public const array AUDIENCE_ICONS = ['anonymous' => 'user-round', 'authenticated' => 'user-check'];

    /** @var list<PeriodMetric> the metrics a period's page compares its versions by, in its columns' order */
    private const array METRICS = [
        PeriodMetric::Starts,
        PeriodMetric::Completions,
        PeriodMetric::Emails,
        PeriodMetric::Registrations,
        PeriodMetric::Activated,
        PeriodMetric::Goal,
    ];

    public function __construct(
        private readonly ComparisonPeriodsQuery $periods,
        private readonly GameMetricsQuery $metrics,
        private readonly TrafficPeriodScopesQuery $scopes,
    ) {}

    /** A period's game, audience and scope: “Trust Your Taste · Anonymous visitors · ExoClick tests”. */
    public static function title(GameTrafficPeriod $period, ?string $scope): string
    {
        return implode(' · ', array_filter([$period->game->title, $period->audience->label(), $scope]));
    }

    /**
     * The moment an account must have signed up before for its activation to
     * count: its seven days after signing up are over.
     */
    public static function maturedBefore(): CarbonImmutable
    {
        return ActivityDay::maturedBefore(CarbonImmutable::now(), (int) config('analytics.activation_days'));
    }

    /**
     * A period's versions compared, from its funnel: each version with its
     * share and whether its flow asks an anonymous visitor for an email.
     *
     * @param  array<int, PeriodVersionFunnel>  $funnels  by version id
     */
    public static function comparison(GameTrafficPeriod $period, GameAudience $audience, array $funnels): PeriodComparison
    {
        return new PeriodComparison(
            $audience,
            $period->versions->map(fn (GameVersion $version): ComparedVersion => new ComparedVersion(
                (int) $version->id,
                (int) $version->number,
                (int) $version->getRelation('pivot')->getAttribute('share_percent'),
                $version->asksAnonymousForEmail(),
            ))->values()->all(),
            $funnels,
        );
    }

    /**
     * The list, filtered and on its page.
     *
     * @return array<string, mixed>
     */
    public function list(string $search, string $show, string $status, int $page): array
    {
        $paginator = $this->periods->paginate($search, $show, $status, $page);

        if ($paginator->currentPage() > $paginator->lastPage()) {
            $paginator = $this->periods->paginate($search, $show, $status, $paginator->lastPage());
        }

        /** @var list<GameTrafficPeriod> $periods */
        $periods = $paginator->items();
        $funnels = $this->metrics->periodFunnel(array_map(fn (GameTrafficPeriod $period): int => (int) $period->id, $periods), self::maturedBefore());
        $filtered = trim($search) !== '' || $show !== 'all' || $status !== 'all';
        $today = CarbonImmutable::now('UTC');
        $scopes = $this->scopes->labels($periods);

        return [
            'paginator' => $paginator,
            'pageNumber' => $paginator->currentPage(),
            'count' => number_format($paginator->total()).' '.str('period')->plural($paginator->total()),
            'filtered' => $filtered,
            'emptyText' => $paginator->total() > 0 ? null : ($filtered || $this->periods->anyListed()
                ? 'No periods match these filters.'
                : 'No traffic periods yet. Each Apply in a game’s Traffic tab starts one.'),
            'rows' => array_map(fn (GameTrafficPeriod $period): array => $this->row($period, $funnels[(int) $period->id] ?? [], $scopes[(int) $period->id] ?? null, $today), $periods),
        ];
    }

    /**
     * The period page's top bar actions: Delete period for an ended period
     * still listed, and the way to the game's Traffic; none for a period the
     * page wouldn't find.
     *
     * @return array{canDelete: bool, trafficUrl: string}|null
     */
    public function topbar(int $periodId): ?array
    {
        $period = $this->periods->find($periodId);

        return $period === null ? null : [
            'canDelete' => $period->ended_at !== null && $period->hidden_at === null,
            'trafficUrl' => GameResource::getUrl('traffic', ['record' => $period->game]),
        ];
    }

    /**
     * One period's page; not found for a period that doesn't exist or never
     * gave its visitors to a version.
     *
     * @return array<string, mixed>
     */
    public function period(int $periodId): array
    {
        $period = $this->periods->find($periodId);

        if ($period === null) {
            return ['notFound' => true];
        }

        $game = $period->game;
        $audience = $period->audience;
        $today = CarbonImmutable::now('UTC');
        $comparison = self::comparison($period, $audience, $this->metrics->periodFunnel([(int) $period->id], self::maturedBefore())[(int) $period->id] ?? []);
        $state = $comparison->state();
        $reference = $comparison->reference();
        $visitors = $comparison->assigned();
        $ended = $period->ended_at !== null;
        $days = self::days($period, $today);
        $scope = $this->scopes->labels([$period])[(int) $period->id] ?? null;

        return [
            'notFound' => false,
            'periodId' => (int) $period->id,
            'title' => self::title($period, $scope),
            'scope' => $scope,
            'ended' => $ended,
            'hidden' => $period->hidden_at !== null,
            'canDelete' => $ended && $period->hidden_at === null,
            'state' => ['label' => $state->label(), 'tone' => $state->tone()],
            'meta' => self::dates($period, false).' · '.number_format($days).' '.str('day')->plural($days).' · '.number_format($visitors).' '.str('visitor')->plural($visitors),
            'group' => implode(' · ', array_filter([mb_strtolower($audience->label()), $period->source_id === null ? null : $scope])),
            'switcher' => $this->periods->siblings($period)->map(fn (GameTrafficPeriod $each): array => [
                'label' => self::dates($each, true, $today),
                'mix' => self::mix($each),
                'current' => (int) $each->id === (int) $period->id,
                'url' => ExperimentsPage::getUrl(['period' => $each->id]),
            ])->values()->all(),
            'note' => self::note($comparison, $state, $ended),
            'heads' => array_map(fn (PeriodMetric $metric): string => $metric->label(), self::METRICS),
            'rows' => array_map(function (ComparedVersion $version) use ($comparison, $state, $reference, $period): array {
                $model = $period->versions->firstWhere('id', $version->id);
                $funnel = $comparison->funnel($version);

                return [
                    'label' => $version->label(),
                    'combo' => ($model?->contentSet->name ?? '—').' + '.($model?->flow->name ?? '—'),
                    'reference' => $comparison->compares() && $reference?->id === $version->id,
                    'share' => $version->share.'%',
                    'visitors' => number_format($funnel->assigned),
                    'cells' => array_map(fn (PeriodMetric $metric): array => self::cell($comparison, $state, $version, $metric), self::METRICS),
                ];
            }, $comparison->versions),
            'foot' => 'Rates use the visitors assigned to each version. '
                .($comparison->compares() && $reference !== null ? 'Deltas compare with '.$reference->label().', the version with the largest share. ' : '')
                .'The data badge follows '.$comparison->primaryNoun().'.',
            'trafficUrl' => GameResource::getUrl('traffic', ['record' => $game]),
            'versionsUrl' => GameResource::getUrl('versions', ['record' => $game]),
            'analyticsUrl' => GameResource::getUrl('analytics', [
                'record' => $game,
                ...self::analyticsRange($period, $today),
                // The game's Analytics opens on anonymous visitors; logged-in players are its other group.
                ...($audience === GameAudience::Authenticated ? ['group' => $audience->value] : []),
            ]),
        ];
    }

    /**
     * A row of the list.
     *
     * @param  array<int, PeriodVersionFunnel>  $funnels
     * @param  string|null  $scope  the period's scope in words, if it reads as more than its audience
     * @return array<string, mixed>
     */
    private function row(GameTrafficPeriod $period, array $funnels, ?string $scope, CarbonImmutable $today): array
    {
        $comparison = self::comparison($period, $period->audience, $funnels);
        $state = $comparison->state();
        $days = self::days($period, $today);
        $visitors = $comparison->assigned();

        return [
            'id' => (int) $period->id,
            'game' => $period->game->title,
            'mechanic' => $period->game->mechanic->label(),
            'icon' => $period->game->mechanic->icon(),
            'audience' => $period->audience->label(),
            'audienceIcon' => self::AUDIENCE_ICONS[$period->audience->value],
            'scope' => $scope,
            'dates' => self::dates($period, false),
            'days' => number_format($days).' '.str('day')->plural($days),
            'mix' => self::mix($period),
            'visitors' => number_format($visitors),
            'state' => ['label' => $state->label(), 'tone' => $state->tone()],
            'current' => $period->ended_at === null,
            'url' => ExperimentsPage::getUrl(['period' => $period->id]),
        ];
    }

    /**
     * One version's figure of one metric: its rate, and under it the count
     * with the difference from the reference — or why it can't be read —
     * coloured once the period has enough data and the difference is
     * 0.3 percentage points or more.
     *
     * @return array{rate: string, sub: string, tone: string|null, dashed: bool, evidence: string|null}
     */
    private static function cell(PeriodComparison $comparison, PeriodDataState $state, ComparedVersion $version, PeriodMetric $metric): array
    {
        $dash = $comparison->dash($version, $metric);
        $rate = $comparison->rate($version, $metric);

        if ($dash !== null || $rate === null) {
            return ['rate' => '—', 'sub' => $dash ?? '—', 'tone' => null, 'dashed' => true, 'evidence' => null];
        }

        $delta = $comparison->delta($version, $metric);
        $rounded = $delta === null ? null : round($delta, 1);
        $evidence = $comparison->evidence($version, $metric);

        return [
            'rate' => number_format($rate, 1).'%',
            'sub' => number_format($comparison->funnel($version)->count($metric)).($rounded === null ? '' : ' · '.($rounded < 0 ? '−' : '+').number_format(abs($rounded), 1).' pp'),
            // Coloured by the figure shown, so a cell reading ±0.3 pp is coloured too.
            'tone' => $rounded === null || $state !== PeriodDataState::Enough || abs($rounded) < self::TONED_FROM ? null : ($rounded > 0 ? 'good' : 'bad'),
            'dashed' => false,
            'evidence' => $evidence instanceof ComparisonEvidence ? $evidence->label() : null,
        ];
    }

    /**
     * What the figures say so far, as the strip above the table puts it.
     *
     * @return array{tone: string, icon: string, text: string}
     */
    private static function note(PeriodComparison $comparison, PeriodDataState $state, bool $ended): array
    {
        return match ($state) {
            PeriodDataState::Enough => ['tone' => 'success', 'icon' => 'circle-check', 'text' => $comparison->audience === GameAudience::Anonymous
                ? 'Enough accounts in every version. Read the metrics together: a version can capture more emails and still activate fewer users.'
                : 'Enough players who reached the goal in every version. Read the metrics together.'],
            PeriodDataState::Early => ['tone' => 'warning', 'icon' => 'clock', 'text' => $comparison->earlyNote().'. Read the numbers as a trend for now.'],
            PeriodDataState::Single => ['tone' => 'info', 'icon' => 'info', 'text' => 'Only '.($comparison->versions[0] ?? null)?->label().' ran in this period, so there’s nothing to compare. Give a second version a share in Traffic to start a comparison.'],
            PeriodDataState::Empty => ['tone' => 'info', 'icon' => 'info', 'text' => $ended
                ? 'Nobody was sent to a version in this period.'
                : 'This period just started. Numbers appear as visitors arrive.'],
        };
    }

    /** The period's versions with their shares: “V7 80% · V8 20%”. */
    public static function mix(GameTrafficPeriod $period): string
    {
        return $period->versions->map(fn (GameVersion $version): string => $version->label().' '.((int) $version->getRelation('pivot')->getAttribute('share_percent')).'%')->implode(' · ');
    }

    /**
     * A period's days as the list and the period's page name them: “24 Sep
     * — 08 Oct 2026”, or “Since 08 Oct 2026” for one still open. Short, for
     * the switcher and Analytics → Experiments, without this year.
     */
    public static function dates(GameTrafficPeriod $period, bool $short, ?CarbonImmutable $today = null): string
    {
        $from = $period->started_at->utc();
        $to = $period->ended_at?->utc();
        $day = fn (CarbonImmutable $moment, bool $withYear): string => $moment->format($withYear ? 'd M Y' : 'd M');

        if ($short) {
            $thisYear = ($today ?? CarbonImmutable::now('UTC'))->year;

            return $to === null
                ? 'Since '.$day($from, $from->year !== $thisYear)
                : $day($from, $from->year !== $to->year).' — '.$day($to, $to->year !== $thisYear);
        }

        return $to === null ? 'Since '.$day($from, true) : $day($from, $from->year !== $to->year).' — '.$day($to, true);
    }

    /** How many days a period ran, by the days it started and ended on — up to today while it is open; one at least. */
    private static function days(GameTrafficPeriod $period, CarbonImmutable $today): int
    {
        $from = $period->started_at->utc()->startOfDay();
        $to = ($period->ended_at ?? $today)->utc()->startOfDay();

        return max(1, (int) round($from->diffInDays($to, true)));
    }

    /**
     * The days of the game's Analytics a period's link opens on: the
     * period's own days, from the first the game's figures still keep; none
     * where the period is older than all of them, which opens Analytics on
     * its own default.
     *
     * @return array{from?: string, to?: string}
     */
    private static function analyticsRange(GameTrafficPeriod $period, CarbonImmutable $today): array
    {
        $earliest = $today->startOfDay()->subDays(GameAnalytics::retentionDays() - 1);
        $from = $period->started_at->utc()->startOfDay();
        $from = $from->lessThan($earliest) ? $earliest : $from;
        $to = ($period->ended_at ?? $today)->utc()->startOfDay();

        return $from->greaterThan($to) ? [] : ['from' => $from->toDateString(), 'to' => $to->toDateString()];
    }
}
