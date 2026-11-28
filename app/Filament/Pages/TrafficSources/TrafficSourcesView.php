<?php

namespace App\Filament\Pages\TrafficSources;

use App\Enums\AccountSignupMethod;
use App\Enums\AccountSignupPlace;
use App\Enums\GeoEdition;
use App\Enums\TrafficBlockLevel;
use App\Enums\TrafficBlockMethod;
use App\Enums\TrafficBlockNetworkState;
use App\Enums\TrafficBlockVerdict;
use App\Enums\TrafficCampaignStatus;
use App\Enums\TrafficChannel;
use App\Enums\TrafficConnectionStatus;
use App\Enums\TrafficConnectorFailure;
use App\Enums\TrafficDimension;
use App\Enums\TrafficEvent;
use App\Enums\TrafficPostbackStatus;
use App\Enums\TrafficSourceStatus;
use App\Enums\TrafficSourceType;
use App\Enums\TrafficSpendMode;
use App\Enums\TrafficSpendOrigin;
use App\Filament\Pages\GrowthAnalyticsPage;
use App\Filament\Pages\TrafficSourcesPage;
use App\Models\Game;
use App\Models\GameVersion;
use App\Models\TrafficApiConnection;
use App\Models\TrafficBlock;
use App\Models\TrafficBlockListing;
use App\Models\TrafficCampaign;
use App\Models\TrafficCampaignEvent;
use App\Models\TrafficCampaignStagePeriod;
use App\Models\TrafficChanceRun;
use App\Models\TrafficConversion;
use App\Models\TrafficPostback;
use App\Models\TrafficPostbackRule;
use App\Models\TrafficPriceSet;
use App\Models\TrafficSetting;
use App\Models\TrafficSource;
use App\Models\TrafficSpendDay;
use App\Models\TrafficStepChance;
use App\Models\TrafficVisit;
use App\Models\TrafficVisitDetail;
use App\Models\User;
use App\Queries\Traffic\TrafficBlocksQuery;
use App\Queries\Traffic\TrafficLogsQuery;
use App\Queries\Traffic\TrafficReachQuery;
use App\Queries\Traffic\TrafficReportQuery;
use App\Queries\Traffic\TrafficSpendQuery;
use App\Queries\Traffic\TrafficWorthQuery;
use App\Queries\Users\UnconfirmedAccountsQuery;
use App\Services\Geo\GeoAttribution;
use App\Services\Traffic\Connectors\ConnectorRegistry;
use App\Services\Traffic\Connectors\EditsBlockLists;
use App\Services\Traffic\TrafficPresetCatalog;
use App\Support\Analytics\JourneyTimeline;
use App\Support\Geo\Countries;
use App\Support\Traffic\Blocking\BlockJudge;
use App\Support\Traffic\Blocking\BlockRule;
use App\Support\Traffic\Links\LinkInspector;
use App\Support\Traffic\Links\TrackingLinkBuilder;
use App\Support\Traffic\Money\TrafficMoney;
use App\Support\Traffic\Postbacks\PostbackSchedule;
use App\Support\Traffic\Postbacks\PostbackTemplate;
use App\Support\Traffic\Postbacks\PostbackUrl;
use App\Support\Traffic\Reports\ReachFigures;
use App\Support\Traffic\Reports\ReachTally;
use App\Support\Traffic\Reports\ReportFigures;
use App\Support\Traffic\Reports\ReportPeriod;
use App\Support\Traffic\Reports\SignupFigures;
use App\Support\Traffic\Reports\SiteZoneList;
use App\Support\Traffic\Reports\TrafficReportFilter;
use App\Support\Traffic\Spend\SpendHint;
use App\Support\Traffic\Worth\Slice;
use App\Support\Traffic\Worth\StepLabels;
use App\Support\Traffic\Worth\StepWorth;
use App\Support\Traffic\Worth\WorthPrices;
use BackedEnum;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What each tab of Growth → Traffic sources shows, read for the tab on
 * screen only and only once the page is in a browser: names for the IDs the
 * figures are grouped by, the tree's level with its ten biggest groups,
 * sites and zones with the block rule's verdict, campaigns, spend by day,
 * the logs. Reads only; every change is the page's, through an Action.
 */
final class TrafficSourcesView
{
    /**
     * The report's views: each tree with the dimensions it opens through, in
     * order; Sites & zones is the flat list instead.
     *
     * @var array<string, array{0: string, 1: list<string>|null}>
     */
    public const array VIEWS = [
        'sources' => ['Sources', ['type', 'source', 'campaign', 'site']],
        'sites' => ['Sites & zones', null],
        'devices' => ['Devices', ['device', 'type', 'source']],
        'countries' => ['Countries', ['country', 'source', 'campaign']],
        'games' => ['Games', ['game', 'type', 'source']],
        'versions' => ['Game versions', ['game', 'version', 'source']],
    ];

    /**
     * What Sign-ups and confirmation splits its sign-ups by, in order: each
     * a TrafficDimension, with the name its switch shows.
     *
     * @var array<string, string>
     */
    public const array SIGNUP_DIMENSIONS = [
        'source' => 'Source',
        'campaign' => 'Campaign',
        'site' => 'Site',
        'zone' => 'Zone',
        'country' => 'Country',
        'device' => 'Device',
        'signup_method' => 'Sign-up method',
        'signup_place' => 'Where they signed up',
    ];

    /** How many rows a level of the tree shows before the rest fold into one. */
    private const int CAP = 10;

    /** How many campaigns of a stage Campaigns lists, the newest first. */
    private const int CAMPAIGN_CAP = 300;

    /** The visit columns a network's impressions are never split by: filtered by one, a report has no impressions to set beside its visits. */
    private const array UNSPLIT = ['device', 'city', 'game_id', 'version_id'];

    /** @var array<string, array{general: array<string, int|string>, rules: array<int, array<string, int|string>>, startRates: array<int, float|null>, openRates: array<int, float|null>, campaignTargets: array<int, string>, sourceTargets: array<int, string|null>, worthKnown: bool, blocks: Collection<string, TrafficBlock>, firstClicks: array<string, CarbonImmutable>, windowFrom: CarbonImmutable, now: CarbonImmutable, reachHints: array<string, array{tip: string, replacesProfit: bool}>}> what the block rule needs, by filter, for this render */
    private array $judged = [];

    /** @var array<string, array<string, CarbonImmutable>> each network site's and zone's first click in the rule's 30 days, by the source read ('*' for all), for this render */
    private array $firstClicks = [];

    /** @var array<string, ReachTally> the network's impressions and the visits that reached RateGuru, by period and the source read ('*' for all), for this render */
    private array $reachRead = [];

    /** @var array<int, string>|null each source's network, by the source, for this render */
    private ?array $networks = null;

    public function __construct(
        private readonly TrafficReportQuery $reports,
        private readonly TrafficReachQuery $reach,
        private readonly TrafficSpendQuery $spend,
        private readonly TrafficLogsQuery $logs,
        private readonly TrafficBlocksQuery $blocks,
        private readonly TrafficPresetCatalog $presets,
        private readonly ConnectorRegistry $connectors,
    ) {}

    /** @return array{source: array<string, mixed>, rules: list<array<string, mixed>>} */
    public static function draftOf(TrafficSource $source): array
    {
        $values = $source->only(['type', 'name', 'link_key', 'preset_key', 'status', 'attribution_days', 'click_id_ttl_hours', 'target_cpa', 'reporting_timezone', 'link_params', 'response_rules',
            'spend_mode', 'spend_estimate', 'cost_unit', 'rate_unit', 'rate_mobile', 'rate_desktop', 'fixed_amount', 'fixed_period', 'fixed_from', 'fixed_to',
            'block_level', 'block_auto', 'block_daily_cap', ...BlockRule::KEYS, 'place_site', 'place_position', 'creatives']);

        return [
            'source' => array_map(fn (mixed $value): mixed => $value instanceof BackedEnum ? $value->value : $value, $values),
            'rules' => $source->postbackRules->map(fn ($rule): array => ['id' => $rule->id, 'event' => $rule->event->value, 'goal_code' => (string) $rule->goal_code, 'needs_event' => (string) $rule->needs_event?->value, 'url_template' => $rule->url_template, 'enabled' => (bool) $rule->enabled])->values()->all(),
        ];
    }

    /**
     * The period on screen as an address names it, read as the page settled
     * it: the preset — named even when it is the default, since an address
     * naming none opens on the period last chosen — and a range's days.
     *
     * @return array<string, string>
     */
    private static function periodQuery(TrafficSourcesPage $page): array
    {
        return array_filter(['period' => (string) $page->period, 'from' => (string) $page->from, 'to' => (string) $page->to], fn (string $value): bool => $value !== '');
    }

    /** @return array<string, mixed> */
    public function data(TrafficSourcesPage $page, TrafficReportFilter $filter): array
    {
        $sources = TrafficSource::query()->orderBy('name')->get();
        $settings = TrafficSetting::current();

        $data = [
            'tabs' => collect(TrafficSourcesPage::TABS)->map(fn (string $label, string $id): array => [
                'id' => $id,
                'label' => $label,
                // Opened in a new browser tab, a tab shows the period on screen, not the one last chosen — maybe in another tab since.
                'href' => TrafficSourcesPage::getUrl([...($id === 'report' ? [] : ['tab' => $id]), ...self::periodQuery($page)]),
                'attributes' => ['x-on:click' => "if (! (\$event.metaKey || \$event.ctrlKey || \$event.shiftKey || \$event.altKey)) { \$event.preventDefault(); \$wire.showTab('{$id}') }"],
                'count' => match ($id) {
                    'campaigns' => ($new = TrafficCampaign::query()->where('status', TrafficCampaignStatus::New)->count()) > 0 ? $new.' new' : null,
                    'logs' => ($failed = TrafficPostback::query()->where('status', TrafficPostbackStatus::Failed)->where('created_at', '>=', now()->subDays(7))->count()) > 0 ? $failed.' failed' : null,
                    default => null,
                },
            ])->values()->all(),
            'sourceOptions' => [['value' => '', 'label' => 'All sources'], ...$sources->map(fn (TrafficSource $source): array => ['value' => (string) $source->id, 'label' => $source->name])->values()->all()],
            'typeOptions' => [['value' => '', 'label' => 'All traffic'], ['value' => 'network', 'label' => 'Ad networks'], ['value' => 'placement', 'label' => 'Banners & links'], ['value' => 'unpaid', 'label' => 'Unpaid']],
            'deviceOptions' => [['value' => '', 'label' => 'All devices'], ['value' => 'mobile', 'label' => 'Mobile'], ['value' => 'desktop', 'label' => 'Desktop']],
            'countryOptions' => $this->countryOptions($filter),
            'gameOptions' => [['value' => '', 'label' => 'All games', 'trigger' => 'All games'], ...Game::query()->orderBy('title')->get(['id', 'title'])->map(fn (Game $game): array => ['value' => (string) $game->id, 'label' => $game->title])->all()],
            'campaignOptions' => [['value' => '', 'label' => 'All campaigns', 'trigger' => 'All'], ...TrafficCampaign::query()->when(is_numeric($page->source), fn ($query) => $query->where('source_id', (int) $page->source))->orderByDesc('first_click_at')->limit(100)->get(['id', 'name', 'external_id'])->map(fn (TrafficCampaign $campaign): array => ['value' => (string) $campaign->id, 'label' => $campaign->name ?? 'Campaign '.$campaign->external_id])->all()],
            'periodDays' => ReportPeriod::span(CarbonImmutable::parse($filter->fromDay, 'UTC'), CarbonImmutable::parse($filter->toDay, 'UTC')),
            'capture' => (bool) $settings->capture_enabled,
            'postbacks' => (bool) $settings->postbacks_enabled,
            'geo' => $this->geo($settings->geo_edition ?? GeoEdition::GeoLite2City),
            'ready' => $page->ready,
            'presetCards' => $page->adding ? $this->presetCards() : null,
            'editor' => $page->edit !== '' ? $this->editor($page, $sources) : null,
            'campaign' => $page->camp !== '' ? $this->campaignPanel((int) $page->camp, $page->ready) : null,
        ];

        if (! $page->ready) {
            return $data;
        }

        return [...$data, ...match ($page->tab) {
            'campaigns' => $this->campaigns($page, $filter, $sources),
            'value' => $this->value($filter, $sources),
            'spend' => $this->spendTab($page, $filter, $sources),
            'sources' => $this->sourcesTab($sources),
            'logs' => $this->logsTab($page, $sources),
            default => $this->report($page, $filter, $sources),
        }];
    }

    /** @return array<string, mixed> */
    private function report(TrafficSourcesPage $page, TrafficReportFilter $filter, $sources): array
    {
        $cutoff = UnconfirmedAccountsQuery::cutoff();
        $summary = $this->reports->summary($filter, $cutoff);
        $sum = fn (iterable $rows): ReportFigures => collect($rows)->reduce(fn (ReportFigures $total, array $row): ReportFigures => $total->plus($row['figures']), new ReportFigures);
        // Spend no visit took counts in the totals, but only where nothing narrows the visits to a game, a device, a country, a campaign or unpaid traffic.
        $unallocated = ! $this->narrowsVisits($filter) && ! (array_key_exists('source_id', $filter->where) && $filter->where['source_id'] === null) && ($filter->where['channel'] ?? 'network') === 'network'
            ? $this->spend->unallocated($filter->fromDay, $filter->toDay, isset($filter->where['source_id']) ? (int) $filter->where['source_id'] : null)
            : [];
        $extra = array_reduce($unallocated, fn (string $total, array $row): string => TrafficMoney::stored(TrafficMoney::of($total)->plus(TrafficMoney::of($row['amount']))), '0');
        $total = $sum($summary)->withSpend($extra);
        $paid = $sum(array_filter($summary, fn (array $row): bool => in_array($row['channel'], ['network', 'placement'], true)))->withSpend($extra);
        $byDevice = [];
        $path = array_fill_keys(['signup_form', 'login', 'late', 'place_game', 'place_post', 'place_feed', 'place_other', 'within_10_minutes', 'within_an_hour', 'within_a_day'], 0);
        $paidNew = 0;
        $signups = new SignupFigures;
        $paidSignups = new SignupFigures;

        foreach ($summary as $row) {
            $signups = $signups->plus($row['signups']);

            if (in_array($row['channel'], ['network', 'placement'], true)) {
                $byDevice[$row['device']] = isset($byDevice[$row['device']]) ? $byDevice[$row['device']]->plus($row['figures']) : $row['figures'];
                $paidNew += $row['figures']->newVisitors();
                $paidSignups = $paidSignups->plus($row['signups']);

                foreach ($row['path'] as $key => $count) {
                    $path[$key] += $count;
                }
            }
        }

        $labels = $this->labels($sources);
        $list = $page->reportView === 'sites' ? $this->siteList($page, $filter, $sources) : null;

        return [
            'total' => $total,
            'paid' => $paid,
            'paidNew' => $paidNew,
            'byDevice' => $byDevice,
            'accountsPath' => $path,
            'paidSignups' => $paidSignups,
            'graceDays' => UnconfirmedAccountsQuery::GRACE_DAYS,
            'signups' => $this->signupCard($page, $filter, $sources, $labels, $cutoff, $signups, $paidSignups),
            'views' => collect(self::VIEWS)->map(fn (array $view): string => $view[0])->all(),
            'chain' => self::VIEWS[$page->reportView][1] === null ? [] : array_map(fn (string $dimension): string => TrafficDimension::from($dimension)->label(), self::VIEWS[$page->reportView][1]),
            'reach' => $this->reachCard($filter, $summary, $sources),
            'tree' => $list === null ? $this->tree($page, $filter, $sources, $labels) : [],
            'list' => $list,
            'worthBlocking' => $list['counts']['block'] ?? $this->worthBlocking($filter, $sources),
            // The networks Worth blocking's general thresholds don't wholly apply to: each set some of its own.
            'ownRules' => $sources->filter(fn (TrafficSource $source): bool => $source->isNetwork() && BlockRule::own($source->only(BlockRule::KEYS)) !== [])->pluck('name')->values()->all(),
            // The hint Worth blocking counts beside the rule: a product rule, the same for every source.
            'reachRule' => ['impressions' => (int) config('traffic.reach.min_impressions'), 'percent' => (int) config('traffic.reach.below_percent_of_source')],
            'unallocated' => $extra,
        ];
    }

    /**
     * Sign-ups and confirmation: the sign-ups of the visits on the filters by
     * the dimension chosen, the most first — the ten biggest rows and the
     * rest in one — and their total, which is How accounts come's own sum
     * over every type of traffic on the filters; with the paid traffic How
     * accounts come counts beside it whenever unpaid sign-ups are among them.
     *
     * @param  array<string, array<string, string>>  $labels
     * @return array<string, mixed>
     */
    private function signupCard(TrafficSourcesPage $page, TrafficReportFilter $filter, $sources, array $labels, CarbonInterface $cutoff, SignupFigures $total, SignupFigures $paid): array
    {
        $dimension = TrafficDimension::from($page->signupsBy);
        $groups = $total->signedUp === 0 ? [] : $this->reports->signups($filter, $dimension, $cutoff);
        $shown = array_slice($groups, 0, self::CAP);
        $shownSum = array_reduce($shown, fn (SignupFigures $sum, array $group): SignupFigures => $sum->plus($group['figures']), new SignupFigures);
        $byRef = in_array($dimension, [TrafficDimension::Campaign, TrafficDimension::Site, TrafficDimension::Zone], true);

        return [
            'by' => $page->signupsBy,
            'dimensions' => self::SIGNUP_DIMENSIONS,
            'heading' => $dimension->label(),
            'rows' => array_map(function (array $group) use ($dimension, $byRef, $sources, $labels): array {
                $keys = $group['keys'];
                $source = isset($keys['source_id']) ? $sources->firstWhere('id', (int) $keys['source_id']) : null;
                $ref = $keys[$dimension->columns()[0]] ?? null;
                // A network with no zones (PopAds) splits by site, as Sites & zones lists it.
                $bySite = $dimension === TrafficDimension::Zone && ($ref === null || $ref === '') && (string) ($keys['site_ref'] ?? '') !== '';
                $level = $bySite ? TrafficDimension::Site : $dimension;
                $ref = $bySite ? $keys['site_ref'] : $ref;
                $name = $source->name ?? 'Source #'.($keys['source_id'] ?? '');

                return [
                    'key' => md5((string) json_encode($keys)),
                    // A campaign, site or zone is the ad networks' own: unpaid traffic has none, and is one row.
                    'label' => $byRef && ($keys['source_id'] ?? null) === null ? 'Unpaid traffic' : $this->nodeLabel($level, $keys, $labels),
                    'sub' => match (true) {
                        $dimension === TrafficDimension::Source => ($keys['source_id'] ?? null) === null ? 'Unpaid' : TrafficChannel::tryFrom((string) ($keys['channel'] ?? ''))?->label(),
                        $bySite => $name.' · no zones, by site',
                        $byRef && ($keys['source_id'] ?? null) !== null => $name,
                        default => null,
                    },
                    'mono' => $dimension === TrafficDimension::Site || $dimension === TrafficDimension::Zone,
                    // A network's site or zone opens in Sites & zones, where it is blocked.
                    'find' => in_array($level, [TrafficDimension::Site, TrafficDimension::Zone], true) && $source?->type === TrafficSourceType::Network && $ref !== null && $ref !== ''
                        ? ['source' => (int) $source->id, 'level' => $level->value, 'ref' => (string) $ref]
                        : null,
                    'figures' => $group['figures'],
                ];
            }, $shown),
            'rest' => count($groups) > count($shown) ? ['count' => count($groups) - count($shown), 'figures' => $total->minus($shownSum)] : null,
            'noun' => match ($dimension) {
                TrafficDimension::Source => 'sources',
                TrafficDimension::Campaign => 'campaigns',
                TrafficDimension::Site => 'sites',
                TrafficDimension::Zone => 'zones',
                TrafficDimension::Country => 'countries',
                default => 'rows',
            },
            'total' => $total,
            'paid' => $paid->signedUp !== $total->signedUp ? $paid : null,
        ];
    }

    /**
     * On the way here: what the ad networks billed by the impression on the
     * filters' period, source and campaign against what reached RateGuru of
     * the same slices, opened the page and started the game — and the
     * impressions paid for that never arrived, with an estimate of what
     * they cost; the days not closed yet named. Narrowed to a country, the
     * network's own figures for it. Narrowed to a device, a city or a game,
     * or to a country it reported nothing for, there are no impressions to
     * set beside the visits: only the networks' visits on the filters show.
     * None where the period has no impressions. Says too what the reach
     * columns of Sites & zones mean on these filters, where that needs saying.
     *
     * @param  list<array{device: string, channel: string, figures: ReportFigures}>  $summary
     * @return array<string, mixed>|null
     */
    private function reachCard(TrafficReportFilter $filter, array $summary, $sources): ?array
    {
        [$sourceId, $campaignId] = self::reachScope($filter);
        // Whether there is anything to leave out is the period's, whichever country is on screen.
        $period = $this->reachTally(new TrafficReportFilter($filter->fromDay, $filter->toDay, array_diff_key($filter->where, ['country' => true])));

        if ($period === null) {
            return null;
        }

        $tally = $this->reachTally($filter) ?? $period;
        $total = $tally->total($sourceId, $campaignId);
        $country = self::reachCountry($filter);

        // A country's own figures can outlive the day's: they are something to show even with none for the whole period.
        if ($period->total($sourceId, $campaignId)->impressions === 0 && $total->impressions === 0) {
            return null;
        }

        if ($this->reachSplit($filter) || $total->impressions === 0) {
            $network = array_reduce(array_filter($summary, fn (array $row): bool => $row['channel'] === TrafficChannel::Network->value), fn (ReportFigures $sum, array $row): ReportFigures => $sum->plus($row['figures']), new ReportFigures);
            $unsplit = array_intersect_key($filter->where, array_flip(self::UNSPLIT)) !== [];

            return [
                'split' => true,
                'leftOut' => $unsplit
                    ? 'The network’s impressions aren’t split by device, city or game, so they’re left out on these filters.'
                    : 'The network reported no impressions for this country, so they’re left out on these filters.',
                'listNote' => ($unsplit
                    ? 'Paid impressions are counted by day, campaign, site and country, not by device, city or game.'
                    : 'The network reported no impressions for this country.').' On these filters Paid impr. and Reached show “—” and no site is marked Low reach.',
                'steps' => [
                    ['name' => 'Visits from ad networks', 'count' => $network->visits, 'of' => null, 'note' => 'On these filters'],
                    ['name' => 'Opened the page', 'count' => $network->opened, 'of' => $network->visits, 'note' => 'Of those visits'],
                    ['name' => 'Started the game', 'count' => $network->started, 'of' => $network->opened, 'note' => 'Of those that opened the page'],
                ],
                'lost' => null,
                'open' => null,
            ];
        }

        $one = count($tally->sources($sourceId, $campaignId)) === 1;

        return [
            'split' => false,
            'listNote' => $country === null ? null : 'Paid impr. and Reached are this country’s. Low reach is judged across all countries, so no site is marked on this filter.',
            'steps' => [
                ['name' => 'Paid impressions (network)', 'count' => $total->impressions, 'of' => null, 'note' => $one ? 'What the network billed for, by its own count' : 'What the networks billed for, by their own count'],
                ['name' => 'Reached '.site_name(), 'count' => $total->reached, 'of' => $total->impressions, 'note' => $country === null ? 'Visits of the same days, campaigns and sites' : 'Visits of the same days, campaigns, sites and country'],
                ['name' => 'Opened the page', 'count' => $total->opened, 'of' => $total->reached, 'note' => 'Of those that reached '.site_name()],
                ['name' => 'Started the game', 'count' => $total->started, 'of' => $total->opened, 'note' => 'Of those that opened the page'],
            ],
            'lost' => 'Paid, never reached: '.number_format($total->neverReached()).' ('.self::share($total->neverReachedRate() ?? 0.0).') · ≈ '.TrafficMoney::format($total->lostAmount()).' of the '.($one ? 'network’s ' : 'networks’ ').TrafficMoney::format($total->amount),
            'open' => $this->openDaysNote($tally->openDays($sourceId, $campaignId), $sources),
        ];
    }

    /**
     * The days not closed yet, as a note: the network's figures for them
     * can still change. Each named in its source's own time zone.
     *
     * @param  array<int, list<string>>  $open  each source's days not closed yet
     */
    private function openDaysNote(array $open, $sources): ?string
    {
        $names = [];

        foreach ($open as $sourceId => $days) {
            $today = CarbonImmutable::now($sources->firstWhere('id', $sourceId)->reporting_timezone ?? 'UTC')->startOfDay();

            foreach ($days as $day) {
                $names[$day] = match ($day) {
                    $today->toDateString() => 'today',
                    $today->subDay()->toDateString() => 'yesterday',
                    default => CarbonImmutable::parse($day)->format('j M'),
                };
            }
        }

        ksort($names);
        $names = array_values(array_unique($names));

        return match (true) {
            $names === [] => null,
            $names === ['today'] => 'Includes today, not closed yet: the network’s figures can lag a few minutes behind.',
            count($names) <= 3 => 'Includes '.Arr::join($names, ', ', ' and ').', not closed yet: the network’s figures for them can still change.',
            default => 'Includes '.count($names).' days not closed yet: the network’s figures for them can still change.',
        };
    }

    /**
     * The networks' impressions on the filters' period and source, folded
     * as they were read (ReachTally), every campaign's: read once a render
     * for each source and country, the read of every source serving any. None
     * where the filters go to traffic no network bills by the impression —
     * unpaid, banners and links.
     */
    private function reachTally(TrafficReportFilter $filter): ?ReachTally
    {
        if ((array_key_exists('source_id', $filter->where) && $filter->where['source_id'] === null) || ($filter->where['channel'] ?? TrafficChannel::Network->value) !== TrafficChannel::Network->value) {
            return null;
        }

        $sourceId = self::reachScope($filter)[0];
        $country = self::reachCountry($filter);
        $period = $filter->fromDay.'|'.$filter->toDay.'|'.($country ?? '').'|';

        return $this->reachRead[$period.'*'] ?? ($this->reachRead[$period.($sourceId ?? '*')] ??= $this->reach->tally($filter->fromDay, $filter->toDay, $sourceId, country: $country));
    }

    /** The country the filters name, if any: a visit with none located has no impressions to set beside it. */
    private static function reachCountry(TrafficReportFilter $filter): ?string
    {
        return isset($filter->where['country']) ? (string) $filter->where['country'] : null;
    }

    /**
     * The Country filter: the countries the networks billed on the period,
     * source and campaign, the most impressions first — and the one chosen,
     * whatever it had, so the filter always reads what is on screen.
     *
     * @return list<array{value: string, label: string, count?: string}>
     */
    private function countryOptions(TrafficReportFilter $filter): array
    {
        $impressions = $this->reach->countries($filter->fromDay, $filter->toDay, ...self::reachScope($filter));
        $chosen = self::reachCountry($filter);

        if ($chosen !== null) {
            $impressions[$chosen] ??= 0;
        }

        $options = [['value' => '', 'label' => 'All countries']];

        foreach ($impressions as $code => $count) {
            $options[] = ['value' => (string) $code, 'label' => Countries::name((string) $code), 'count' => number_format($count)];
        }

        return $options;
    }

    /**
     * The source and the campaign the filters name, if any: a figure of one
     * campaign matched only that campaign's visits, so the read of every
     * campaign holds each one's.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private static function reachScope(TrafficReportFilter $filter): array
    {
        return [
            isset($filter->where['source_id']) ? (int) $filter->where['source_id'] : null,
            isset($filter->where['campaign_id']) ? (int) $filter->where['campaign_id'] : null,
        ];
    }

    /** Whether the filters narrow the visits by what a network's impressions are never split by. */
    private function reachSplit(TrafficReportFilter $filter): bool
    {
        return array_intersect_key($filter->where, array_flip(self::UNSPLIT)) !== []
            || (array_key_exists('country', $filter->where) && $filter->where['country'] === null);
    }

    /**
     * The networks' impressions and the visits that reached RateGuru by site
     * and zone, keyed as their blocks — "source:site:ref", "source:zone:ref"
     * — each slice at its narrowest: a zone the network reported, else its
     * site. None on filters the impressions are never split by.
     *
     * @return array<string, ReachFigures>
     */
    private function reachByPlace(TrafficReportFilter $filter): array
    {
        $tally = $this->reachSplit($filter) ? null : $this->reachTally($filter);

        return $tally?->places(...self::reachScope($filter)) ?? [];
    }

    /**
     * The sites and zones whose paid impressions mostly never reach
     * RateGuru, keyed as their blocks, each with why and whether the hint
     * may take the place of Profitable: over the period's closed days, at
     * least so many impressions, and reached below a share of what its
     * source's reached over the same days — of every campaign, whichever one
     * the filters name. Profitable gives way where no closed figure of it is
     * one its visits' spend was shared out from: its spend then counts the
     * visits that came, never what the network billed for those that did
     * not — and, judgeRow() adds, wherever no visit came at all. A hint,
     * never blocked by itself. Judged over every country: a block takes the
     * whole site, so one country's figures do not mark it.
     *
     * @return array<string, array{tip: string, replacesProfit: bool}>
     */
    private function reachHints(TrafficReportFilter $filter): array
    {
        $tally = $this->reachSplit($filter) || self::reachCountry($filter) !== null ? null : $this->reachTally($filter);

        if ($tally === null) {
            return [];
        }

        [$sourceId, $campaignId] = self::reachScope($filter);
        $bySource = $tally->closedBySource($sourceId);
        $inSpend = $tally->placesInSpend($sourceId, $campaignId);
        $least = (int) config('traffic.reach.min_impressions');
        $below = (int) config('traffic.reach.below_percent_of_source');
        $hints = [];

        foreach ($tally->places($sourceId, $campaignId, closedOnly: true) as $key => $place) {
            $source = $bySource[(int) explode(':', $key)[0]] ?? null;

            if ($source !== null && $place->fallsShortOf($source, $least, $below)) {
                $hints[$key] = [
                    'tip' => 'Paid impressions mostly never reach '.site_name().': '.number_format(($place->reachRate() ?? 0) * 100, 0).'% reached against '.number_format(($source->reachRate() ?? 0) * 100, 0).'% for the source, over '.number_format($place->impressions).' paid impressions of closed days',
                    'replacesProfit' => ! isset($inSpend[$key]),
                ];
            }
        }

        return $hints;
    }

    /** A share as the report's lines read it: whole percents from 10%, one decimal under. */
    private static function share(float $rate): string
    {
        return ($rate * 100 >= 10 ? number_format($rate * 100, 0) : number_format($rate * 100, 1)).'%';
    }

    /**
     * The tree of the view on screen: each level's rows, biggest spend first,
     * its ten biggest and the rest in one row, and under each row opened the
     * next dimension's level, to the depth the view goes.
     *
     * @return list<array<string, mixed>>
     */
    private function tree(TrafficSourcesPage $page, TrafficReportFilter $filter, $sources, array $labels): array
    {
        $chain = self::VIEWS[$page->reportView][1] ?? self::VIEWS['sources'][1];
        $rows = [];
        $this->branch($page, $chain, 0, $filter, '', $sources, $labels, $rows);

        return $rows;
    }

    /**
     * One level of the tree, and the levels opened under it.
     *
     * @param  list<string>  $chain
     * @param  list<array<string, mixed>>  $rows
     */
    private function branch(TrafficSourcesPage $page, array $chain, int $depth, TrafficReportFilter $filter, string $parent, $sources, array $labels, array &$rows): void
    {
        $dimension = TrafficDimension::from($chain[$depth]);
        $groups = $this->reports->level($filter, $dimension);

        // Unpaid traffic is one type: its channels are its sources.
        if ($dimension === TrafficDimension::Type) {
            $unpaid = array_filter($groups, fn (array $group): bool => ! in_array($group['keys']['channel'], ['network', 'placement'], true));
            $groups = [
                ...array_values(array_filter($groups, fn (array $group): bool => in_array($group['keys']['channel'], ['network', 'placement'], true))),
                ...($unpaid === [] ? [] : [['keys' => ['channel' => 'unpaid'], 'figures' => collect($unpaid)->reduce(fn (ReportFigures $sum, array $group): ReportFigures => $sum->plus($group['figures']), new ReportFigures), 'count' => count($unpaid)]]),
            ];
            $order = ['network' => 0, 'placement' => 1, 'unpaid' => 2];
            usort($groups, fn (array $a, array $b): int => $order[$a['keys']['channel']] <=> $order[$b['keys']['channel']]);
        } else {
            usort($groups, fn (array $a, array $b): int => [(float) $b['figures']->spend, $b['figures']->visits] <=> [(float) $a['figures']->spend, $a['figures']->visits]);
        }

        $levelKey = $parent.'#'.$dimension->value;
        $shown = in_array($levelKey, $page->more, true) ? $groups : array_slice($groups, 0, self::CAP);
        $last = $depth + 1 >= count($chain);
        $blockable = in_array($dimension, [TrafficDimension::Site, TrafficDimension::Zone], true);
        $judge = $blockable ? $this->judgeContext($filter) : null;

        foreach ($shown as $group) {
            $keys = $group['keys'];
            $value = $dimension === TrafficDimension::Source && $keys['source_id'] === null ? 'c:'.$keys['channel'] : ($keys[$dimension->columns()[0]] ?? '');
            $key = ltrim($parent.'|'.$dimension->value.'='.$value, '|');
            $open = ! $last && in_array($key, $page->open, true);
            $row = [
                'kind' => 'node',
                'depth' => $depth,
                'key' => $key,
                'label' => $this->nodeLabel($dimension, $keys, $labels),
                'sub' => $dimension === TrafficDimension::Type ? $this->typeNote((string) $keys['channel'], $group['count'] ?? null) : null,
                'mono' => $blockable,
                'figures' => $group['figures'],
                'children' => ! $last,
                'open' => $open,
                'flag' => null,
            ];

            if ($judge !== null && isset($keys['source_id'])) {
                $ref = (string) ($keys[$dimension->columns()[0]] ?? '');
                $blockKey = $keys['source_id'].':'.$dimension->value.':'.$ref;
                $block = $judge['blocks']->get($blockKey);
                // A site under a campaign's row came through that campaign.
                $campaignId = isset($filter->where['campaign_id']) ? (int) $filter->where['campaign_id'] : null;
                $row['flag'] = $this->flag($this->judgeRow($judge, $blockKey, (int) $keys['source_id'], $group['figures'], $campaignId, $group['first_at'] ?? null, $block === null), $block);
            }

            $rows[] = $row;

            if ($open) {
                $child = $dimension === TrafficDimension::Type && $keys['channel'] === 'unpaid'
                    ? new TrafficReportFilter($filter->fromDay, $filter->toDay, [...$filter->where, 'source_id' => null])
                    : $filter->within($dimension, $keys);
                $this->branch($page, $chain, $depth + 1, $child, $key, $sources, $labels, $rows);
            }
        }

        if (count($groups) > count($shown)) {
            $rest = array_slice($groups, count($shown));
            $rows[] = [
                'kind' => 'more',
                'depth' => $depth,
                'key' => $levelKey,
                'count' => count($rest),
                'figures' => collect($rest)->reduce(fn (ReportFigures $sum, array $group): ReportFigures => $sum->plus($group['figures']), new ReportFigures),
                'noun' => strtolower($dimension->label()).'s',
                'list' => $blockable && isset($filter->where['source_id']) ? ['source' => (int) $filter->where['source_id'], 'level' => $dimension->value] : null,
            ];
        }
    }

    /** @param  array<string, int|string|null>  $keys */
    private function nodeLabel(TrafficDimension $dimension, array $keys, array $labels): string
    {
        $column = $dimension->columns()[0];
        $value = $keys[$column] ?? null;

        return match (true) {
            $dimension === TrafficDimension::Type => ['network' => 'Ad networks', 'placement' => 'Banners & links', 'unpaid' => 'Unpaid'][(string) $value] ?? ucfirst((string) $value),
            $dimension === TrafficDimension::Source && $value === null => TrafficChannel::tryFrom((string) ($keys['channel'] ?? ''))?->label() ?? 'Unpaid',
            $value === null || $value === '' => match ($column) {
                'campaign_id' => 'No campaign',
                'game_id' => 'No game',
                'version_id' => 'No version',
                'site_ref', 'zone_ref' => 'No ID',
                default => 'Unknown',
            },
            $column === 'site_ref' => 'site '.$value,
            $column === 'zone_ref' => 'zone '.$value,
            $column === 'device' => ucfirst((string) $value),
            default => $labels[$column][(string) $value] ?? (string) $value,
        };
    }

    private function typeNote(string $type, ?int $count): string
    {
        return match ($type) {
            'network' => 'Paid per click or impression',
            'placement' => 'At a fixed price',
            default => ($count ?? 0).' '.(($count ?? 0) === 1 ? 'channel' : 'channels').' · costs nothing',
        };
    }

    /**
     * What the block rule needs to judge a site or zone on the filters: the
     * general thresholds and each source's — its own where it set them —
     * each source's start and opened rates over the same visits, the target
     * prices of an account of the campaigns and the sources, whether worth is
     * measured yet, the blocks, each site's and zone's first click in the
     * rule's own 30 days with the day they start — the days a losing site
     * waits run from there, as traffic:block-losers counts them, whatever
     * period the report shows — the moment it judges at, and the sites and
     * zones whose paid impressions mostly never reach RateGuru.
     *
     * @return array{general: array<string, int|string>, rules: array<int, array<string, int|string>>, startRates: array<int, float|null>, openRates: array<int, float|null>, campaignTargets: array<int, string>, sourceTargets: array<int, string|null>, worthKnown: bool, blocks: Collection<string, TrafficBlock>, firstClicks: array<string, CarbonImmutable>, windowFrom: CarbonImmutable, now: CarbonImmutable, reachHints: array<string, array{tip: string, replacesProfit: bool}>}
     */
    private function judgeContext(TrafficReportFilter $filter): array
    {
        $id = md5(serialize($filter));

        if (isset($this->judged[$id])) {
            return $this->judged[$id];
        }

        $now = CarbonImmutable::now();
        $month = TrafficReportFilter::lastDays(30, $now);
        $general = BlockRule::effective(TrafficSetting::current()->only(BlockRule::KEYS));
        $sources = TrafficSource::query()->get(['id', 'target_cpa', ...BlockRule::KEYS]);
        $run = TrafficChanceRun::query()->where('status', 'finished')->orderByDesc('id')->first();
        $startRates = [];
        $openRates = [];

        foreach ($this->reports->level(new TrafficReportFilter($filter->fromDay, $filter->toDay, array_diff_key($filter->where, array_flip(['site_ref', 'zone_ref', 'campaign_id']))), TrafficDimension::Source) as $group) {
            if ($group['keys']['source_id'] !== null) {
                $startRates[(int) $group['keys']['source_id']] = $group['figures']->startRate();
                $openRates[(int) $group['keys']['source_id']] = $group['figures']->openRate();
            }
        }

        return $this->judged[$id] = [
            'general' => $general,
            'rules' => $sources->mapWithKeys(fn (TrafficSource $source): array => [(int) $source->id => BlockRule::effective($general, $source->only(BlockRule::KEYS))])->all(),
            'startRates' => $startRates,
            'openRates' => $openRates,
            'campaignTargets' => TrafficCampaign::query()->whereNotNull('target_cpa')->pluck('target_cpa', 'id')->map(fn (mixed $target): string => (string) $target)->all(),
            'sourceTargets' => $sources->mapWithKeys(fn (TrafficSource $source): array => [(int) $source->id => $source->target_cpa])->all(),
            'worthKnown' => $run !== null && (int) TrafficStepChance::query()->where('run_id', $run->id)->where('slice_key', '*')->where('step', 'opened')->value('slice_accounts') >= 30,
            'blocks' => $this->blocksWithListings()->keyBy(fn (TrafficBlock $block): string => $block->source_id.':'.$block->level->value.':'.$block->ref),
            'firstClicks' => $this->firstClicks($month, isset($filter->where['source_id']) ? (int) $filter->where['source_id'] : null),
            'windowFrom' => CarbonImmutable::parse($month->fromDay, 'UTC'),
            'now' => $now,
            'reachHints' => $this->reachHints($filter),
        ];
    }

    /**
     * Each network site's and zone's first click in the rule's 30 days, of
     * one source or of all: read once a render, the read of all serving any
     * source.
     *
     * @return array<string, CarbonImmutable>
     */
    private function firstClicks(TrafficReportFilter $month, ?int $sourceId): array
    {
        if (isset($this->firstClicks['*'])) {
            return $this->firstClicks['*'];
        }

        return $this->firstClicks[$sourceId ?? '*'] ??= $this->reports->firstClicks(
            new TrafficReportFilter($month->fromDay, $month->toDay, $sourceId === null ? [] : ['source_id' => $sourceId]),
        );
    }

    /**
     * One site or zone — by its key, as its block's — judged by its source's
     * thresholds and the target price of an account of its campaign — when
     * it came through one — or of its source, its days of waiting counted
     * from its first click in the rule's 30 days (the period's only for one
     * the rule's days never saw) — or, where the rule calls it too early or
     * below the line, or profitable on a spend that leaves out what the
     * impressions that never arrived cost or on no visit at all, hinted at
     * for paid impressions that mostly never reach RateGuru; and, when
     * asked, why, in the words its badge's tip says it.
     *
     * @param  array{general: array<string, int|string>, rules: array<int, array<string, int|string>>, startRates: array<int, float|null>, openRates: array<int, float|null>, campaignTargets: array<int, string>, sourceTargets: array<int, string|null>, worthKnown: bool, blocks: Collection<string, TrafficBlock>, firstClicks: array<string, CarbonImmutable>, windowFrom: CarbonImmutable, now: CarbonImmutable, reachHints: array<string, array{tip: string, replacesProfit: bool}>}  $judge
     * @return array{verdict: TrafficBlockVerdict|null, state: string, tip: string|null, from: CarbonImmutable|null}
     */
    private function judgeRow(array $judge, string $key, int $sourceId, ReportFigures $figures, ?int $campaignId, ?CarbonImmutable $periodFirstAt, bool $explain = false): array
    {
        $rule = $judge['rules'][$sourceId] ?? $judge['general'];
        $openRate = $judge['openRates'][$sourceId] ?? null;
        $target = BlockRule::target($campaignId !== null ? ($judge['campaignTargets'][$campaignId] ?? null) : null, $judge['sourceTargets'][$sourceId] ?? null);
        $firstAt = $judge['firstClicks'][$key] ?? $periodFirstAt;
        $judged = BlockJudge::judge($figures, $rule, $judge['startRates'][$sourceId] ?? null, $openRate, $judge['worthKnown'], $target['amount'] ?? null, $firstAt, $judge['now'], $judge['windowFrom']);
        $from = BlockJudge::judgedFrom($rule, $firstAt, $judge['windowFrom']);
        $hint = $judge['reachHints'][$key] ?? null;

        // The hint stands in for too early and below the line, and for Profitable where the spend leaves out what the lost impressions cost
        // or no visit took any — a place with none is profitable on nothing, once the money's gate is 0: never for a verdict, nor for a losing site's wait.
        if ($hint !== null && (in_array($judged['state'], ['early', 'watch'], true) || ($judged['state'] === 'profit' && ($hint['replacesProfit'] || $figures->visits === 0)))) {
            return ['verdict' => null, 'state' => 'low_reach', 'tip' => $explain ? $hint['tip'] : null, 'from' => $from];
        }

        return [...$judged, 'tip' => $explain ? $this->verdictTip($judged['state'], $figures, $rule, $openRate, $judge['startRates'][$sourceId] ?? null, $target, $firstAt, $from) : null, 'from' => $from];
    }

    /**
     * Why the rule calls a site what it does, with the values that applied:
     * the share it opened the page at against the share it is held to — the
     * rule's, a share of its source's average, or the floor below which a
     * site is bots always — and its source's average; its ROI against the
     * line, and what it had to spend first — so many times its campaign's or
     * its source's target, or the rule's spend or clicks; and, while it
     * waits, until when; how often those who opened the page started the
     * game, against the share of its source's that it fell under.
     *
     * @param  array<string, int|string>  $rule
     * @param  array{amount: string, from: 'campaign'|'source'}|null  $target
     */
    private function verdictTip(string $state, ReportFigures $figures, array $rule, ?float $openRate, ?float $startRate, ?array $target, ?CarbonImmutable $firstAt, ?CarbonImmutable $from): ?string
    {
        $percent = fn (float $value): string => (abs($value - round($value)) < 0.05 ? number_format($value, 0) : number_format($value, 1)).'%';
        $signed = fn (float $value): string => ($value < 0 ? '−' : '').$percent(abs($value));
        $needed = BlockJudge::spendNeeded($rule, $target['amount'] ?? null);
        $money = $target !== null && $needed !== null
            ? self::multiple((string) $rule['block_target_multiple']).' × the '.($target['from'] === 'campaign' ? 'campaign’s' : 'source’s').' target price of an account, '.TrafficMoney::format($target['amount']).', so '.TrafficMoney::format($needed)
            : TrafficMoney::format($rule['block_min_spend']).' spent or '.number_format((int) $rule['block_min_clicks']).' clicks';
        $roi = $figures->roi();

        return match ($state) {
            'bots' => 'Meets the block rule: '.$percent($figures->opened * 100 / max(1, $figures->visits)).' of '.number_format($figures->visits).' clicks opened the page, under '
                .match (true) {
                    BlockJudge::openBelowFromFloor($rule, $openRate) => 'the rule’s floor of '.(int) $rule['block_open_floor'].'%, bots below it always'.($openRate !== null ? ' (the source averages '.$percent($openRate * 100).')' : ''),
                    BlockJudge::openBelowFromAverage($rule, $openRate) => $percent(BlockJudge::openBelow($rule, $openRate)).': '.(int) $rule['block_open_below_average'].'% of the source’s average of '.$percent(($openRate ?? 0) * 100),
                    default => 'the rule’s '.(int) $rule['block_open_below'].'%'.($openRate !== null ? ' (the source averages '.$percent($openRate * 100).')' : ''),
                },
            'losing' => 'Meets the block rule: ROI '.$signed((float) $roi).', below '.$signed((float) (int) $rule['block_roi_below']).', with '.TrafficMoney::format($figures->spend).' spent — past '.$money,
            'waiting' => 'ROI '.$signed((float) $roi).' is below '.$signed((float) (int) $rule['block_roi_below']).', but the rule waits '.(int) $rule['block_grace_days'].' '.Str::plural('day', (int) $rule['block_grace_days']).' after its first click in the last 30 days'
                .($firstAt !== null ? ', on '.$firstAt->format('j M') : '').', so its accounts can arrive: losing is judged from '.$from?->format('j M H:i').'.',
            'weak_start' => 'Starts the game far less often than the source’s other places: '.$percent(($figures->startRate() ?? 0) * 100).' of the '.number_format($figures->opened)
                .' who opened the page started it, under '.(int) $rule['block_start_percent'].'% of the source’s '.$percent(($startRate ?? 0) * 100),
            'profit' => 'Enough data, value above spend',
            default => null,
        };
    }

    /** A multiple of the target price as it reads: 2, 1.5. */
    private static function multiple(string $multiple): string
    {
        return str_contains($multiple, '.') ? rtrim(rtrim($multiple, '0'), '.') : $multiple;
    }

    /**
     * A site's or zone's badge: its block, else what the rule calls it, with
     * why in its tip.
     *
     * @param  array{state: string, tip: string|null, from: CarbonImmutable|null}  $judged
     * @return array{label: string, tone: string, tip: string}|null
     */
    private function flag(array $judged, ?TrafficBlock $block): ?array
    {
        if ($block !== null) {
            return $this->blockFlag($block);
        }

        return match ($judged['state']) {
            'bots' => ['label' => 'Likely bots', 'tone' => 'danger', 'tip' => (string) $judged['tip']],
            'losing' => ['label' => 'Losing', 'tone' => 'danger', 'tip' => (string) $judged['tip']],
            'waiting' => ['label' => 'Judged from '.$judged['from']?->format('j M'), 'tone' => 'warning', 'tip' => (string) $judged['tip']],
            'weak_start' => ['label' => 'Weak start', 'tone' => 'warning', 'tip' => (string) $judged['tip']],
            'low_reach' => ['label' => 'Low reach', 'tone' => 'warning', 'tip' => (string) $judged['tip']],
            'profit' => ['label' => 'Profitable', 'tone' => 'success', 'tip' => (string) $judged['tip']],
            default => null,
        };
    }

    /**
     * A blocked site's or zone's badge: where it stands with the network —
     * on its way to the lists or off them; on some campaigns' lists only,
     * naming those it isn't on and why; refused by every one; taken off them
     * in the network's panel; a taking off that failed, which leaves it
     * blocked until it is unblocked again; blocked in the network's own
     * panel, not by RateGuru, on how many campaigns' lists, or still there
     * where an unblock failed — or blocked, still sending clicks or not.
     *
     * @return array{label: string, tone: string, tip: string}
     */
    private function blockFlag(TrafficBlock $block): array
    {
        $still = $block->clicks_after > 0;
        $since = $still ? ' · '.$block->clicks_after.' clicks since' : '';
        $tip = 'Blocked '.$block->blocked_at->format('j M').match ($block->method) {
            TrafficBlockMethod::Api => ' through the network',
            TrafficBlockMethod::Auto => ' by the block rule',
            default => ' by hand',
        }.$since;
        $why = fn (?string $error): ?string => $error === null ? null : (TrafficConnectorFailure::tryFrom($error)?->message() ?? 'No campaign of the source to apply it to.');
        $network = $this->networkName((int) $block->source_id);
        $listings = $block->listings;
        $on = $listings->where('listed', true)->values();
        $off = $listings->where('listed', false)->values();
        $stuck = $on->first(fn (TrafficBlockListing $listing): bool => $listing->error !== null);
        $name = fn (TrafficBlockListing $listing): string => $listing->campaign->name ?? 'Campaign '.$listing->campaign->external_id;
        $lists = fn (Collection $some): string => ($some->count() === 1 ? 'the list of ' : 'the lists of ').$some->map($name)->implode(', ');
        // How many campaigns' lists hold a block found in the panel, counted with the blocks; their names aren't read for it.
        $count = (int) ($block->getAttribute('lists_holding') ?? $on->count());
        $holding = $count === 0 ? '' : ' · on '.$count.' '.($count === 1 ? 'campaign’s list' : 'campaigns’ lists');
        $notOn = fn (TrafficBlockListing $listing): string => 'not on '.$name($listing).'’s: '.match ($listing->error) {
            // RateGuru's own block goes by itself to a campaign it is missing from, within minutes.
            null, TrafficBlockListing::PENDING => 'not sent there yet — it goes there within minutes.',
            TrafficBlockListing::TAKEN_OFF => 'taken off its list in the '.$network.' panel, not by '.site_name().'.',
            default => (string) $why($listing->error),
        };

        return match (true) {
            $block->network_state === TrafficBlockNetworkState::Pending => ['label' => 'Blocking…', 'tone' => 'warning', 'tip' => $tip.' · waiting to be added to the network’s list'],
            $block->network_state === TrafficBlockNetworkState::Removing => ['label' => 'Unblocking…', 'tone' => 'warning', 'tip' => 'Waiting to be taken off the network’s list'],
            $block->method === TrafficBlockMethod::Panel && $block->network_error !== null => ['label' => 'Blocked on '.$network.' · unblock failed', 'tone' => 'danger',
                'tip' => 'Blocked in the '.$network.' panel, not by '.site_name().' · still on '.($on->isNotEmpty() ? $lists($on) : 'the network’s list').': '.$why($block->network_error).' Unblock it again to try again.'],
            $block->method === TrafficBlockMethod::Panel => ['label' => 'Blocked on '.$network, 'tone' => $still ? 'danger' : 'neutral',
                'tip' => 'Blocked in the '.$network.' panel, not by '.site_name().' · found '.$block->blocked_at->format('j M').$holding.$since],
            $block->network_state === TrafficBlockNetworkState::Partial && $stuck !== null => ['label' => 'Partly blocked · unblock failed', 'tone' => 'danger',
                // Off the lists with nothing said against them: one that refused to take the block never held it, so it was never taken off.
                'tip' => 'Still on '.$lists($on).': '.$why($stuck->error).($off->whereNull('error')->isNotEmpty() ? ' Off '.$lists($off->whereNull('error')->values()).'.' : '').' Unblock it again to try again.'],
            $block->network_state === TrafficBlockNetworkState::Partial => ['label' => 'Partly blocked · '.$on->count().' of '.$listings->count().' campaigns', 'tone' => 'warning',
                'tip' => $tip.' · on '.$lists($on).' · '.$off->map($notOn)->implode(' · ')],
            $block->network_state === TrafficBlockNetworkState::TakenOff => ['label' => 'Taken off on the network', 'tone' => 'warning',
                'tip' => $tip.' · taken off '.$lists($off->where('error', TrafficBlockListing::TAKEN_OFF)->values()).' in the '.$network.' panel, not by '.site_name().'. Push again to put it back, or Unblock to let it go.'],
            $block->network_state === TrafficBlockNetworkState::Failed => ['label' => 'Not blocked on the network', 'tone' => 'danger',
                'tip' => $tip.' · the network didn’t take it'.($off->isEmpty() ? ': '.$why($block->network_error) : ' · '.$off->map($notOn)->implode(' · '))],
            $block->network_state === TrafficBlockNetworkState::Applied && $block->network_error !== null => ['label' => 'Blocked · unblock failed', 'tone' => 'danger',
                'tip' => 'Still on the network’s list: '.$why($block->network_error).' Unblock it again to try again.'],
            default => ['label' => $still ? 'Blocked · still sending' : 'Blocked', 'tone' => $still ? 'danger' : 'neutral', 'tip' => $tip],
        };
    }

    /**
     * Every block, with how many campaigns' lists hold it; those whose badge
     * names campaigns — on some lists only, on none, taken off them, or
     * found in the network's panel and still there after an unblock failed
     * — with where they stand on each campaign's list and the campaigns'
     * names; the rest with none read. A block found in the panel is often
     * on every list: its listings, as many as its campaigns, are counted,
     * never loaded, on every render.
     *
     * @return EloquentCollection<int, TrafficBlock>
     */
    private function blocksWithListings(): EloquentCollection
    {
        $blocks = TrafficBlock::query()->withCount(['listings as lists_holding' => fn ($listings) => $listings->where('listed', true)])->get();
        $named = $blocks->filter(fn (TrafficBlock $block): bool => ($block->method === TrafficBlockMethod::Panel && $block->network_error !== null)
            || in_array($block->network_state, [TrafficBlockNetworkState::Partial, TrafficBlockNetworkState::Failed, TrafficBlockNetworkState::TakenOff], true));
        (new EloquentCollection($named->all()))->load('listings.campaign:id,name,external_id');

        foreach ($blocks as $block) {
            if (! $block->relationLoaded('listings')) {
                $block->setRelation('listings', new EloquentCollection);
            }
        }

        return $blocks;
    }

    /** A source's network as its own panel is named — "the PopAds panel" — read once a render. */
    private function networkName(int $sourceId): string
    {
        $this->networks ??= TrafficSource::query()->whereNotNull('connector')->get(['id', 'connector'])
            ->mapWithKeys(fn (TrafficSource $source): array => [(int) $source->id => (string) $source->connector?->label()])->all();

        return $this->networks[$sourceId] ?? 'network’s';
    }

    /**
     * Sites & zones: every network site or zone on the filters as one list,
     * each judged by the block rule and put in its segment, then searched,
     * thresholded, sorted and paged — the rows read already where given.
     *
     * @param  list<array<string, mixed>>|null  $rows
     * @return array<string, mixed>
     */
    private function siteList(TrafficSourcesPage $page, TrafficReportFilter $filter, $sources, ?array $rows = null): array
    {
        $rows ??= $this->siteRows($page, $filter, $sources);
        $share = $page->shareColumn !== '' && $page->sharePercent !== '' ? ['column' => (string) $page->shareColumn, 'side' => $page->shareSide === 'above' ? 'above' : 'below', 'percent' => (float) $page->sharePercent] : null;
        $list = SiteZoneList::of($rows, (string) $page->segment, (string) $page->q, (int) $page->minClicks, (string) $page->sort, (string) $page->dir, (int) $page->pageNumber, $share);
        $all = collect($rows)->filter(fn (array $row): bool => SiteZoneList::clears($row, (int) $page->minClicks));
        $spend = $all->sum(fn (array $row): float => (float) $row['figures']->spend);
        $running = 0.0;
        $biggest = 0;

        foreach ($all->sortByDesc(fn (array $row): float => (float) $row['figures']->spend) as $row) {
            if ($spend <= 0 || $running >= 0.8 * $spend) {
                break;
            }

            $running += (float) $row['figures']->spend;
            $biggest++;
        }

        $early = $all->filter(fn (array $row): bool => SiteZoneList::segmentOf($row) === 'early');
        $judge = $this->judgeContext($filter);
        // One source on screen: its own thresholds; every source: the general ones, which a source may have set its own in place of.
        $one = isset($filter->where['source_id']) ? (int) $filter->where['source_id'] : null;
        $rule = $one !== null ? ($judge['rules'][$one] ?? $judge['general']) : $judge['general'];
        $targets = $one !== null ? (BlockRule::target(null, $judge['sourceTargets'][$one] ?? null) !== null || $judge['campaignTargets'] !== []) : ($judge['campaignTargets'] !== [] || array_filter($judge['sourceTargets']) !== []);
        $own = $one === null && collect($judge['rules'])->contains(fn (array $sourceRule): bool => $sourceRule !== $judge['general']);
        // Looked up by key, not searched for: Select all may have ticked every one of tens of thousands of rows.
        $picked = array_flip($page->selected);
        $ticked = collect($rows)->filter(fn (array $row): bool => isset($picked[$row['key']]));

        return [
            ...$list,
            'noun' => $page->listBy === 'site' ? 'sites' : 'zones',
            'insight' => $all->isEmpty() ? null : $all->count().' '.($page->listBy === 'site' ? 'sites' : 'zones').'.'
                .($biggest > 0 ? ' The '.$biggest.' biggest take 80% of the spend: sort by Spend and start there.' : '')
                .($early->isNotEmpty() ? ' '.$early->count().' are too early to judge the money (under '.TrafficMoney::format($rule['block_min_spend']).' and '.number_format((int) $rule['block_min_clicks']).' clicks each'
                    .($targets ? ', or under '.self::multiple((string) $rule['block_target_multiple']).' × the target price of an account where one is set' : '').') yet spent '.TrafficMoney::format($early->sum(fn (array $row): float => (float) $row['figures']->spend)).' together; bots show after '.number_format((int) $rule['block_bot_clicks']).' clicks and a weak start after '.number_format((int) $rule['block_start_clicks']).', so those are caught early.'
                    .($own ? ' Sources with thresholds of their own are judged by them.' : '') : ''),
            'selectable' => collect($list['rows'])->filter(fn (array $row): bool => $page->segment === 'blocked' ? $row['blocked'] : ! $row['blocked'])->pluck('key')->values()->all(),
            'selected' => count($page->selected),
            'selectedBlocked' => $ticked->where('blocked', true)->count(),
            'copyIds' => $ticked->pluck('ref')->implode("\n"),
            'from' => count($list['matching']) === 0 ? 0 : ($list['page'] - 1) * SiteZoneList::PER_PAGE + 1,
            'to' => min(count($list['matching']), $list['page'] * SiteZoneList::PER_PAGE),
        ];
    }

    /**
     * The networks' sites or zones on the filters, judged: a zone where the
     * network names zones, else its site — those the visits came from, and
     * those the network billed enough impressions for that sent none
     * (places()); each with the impressions its network reported for it and
     * how many reached RateGuru, where the network reports them at its level.
     *
     * @return list<array{key: string, ref: string, level: string, source: string, source_id: int, campaign: string|null, state: string, early: string|null, blocked: bool, flag: array<string, string>|null, figures: ReportFigures, reach: ReachFigures|null}>
     */
    private function siteRows(TrafficSourcesPage $page, TrafficReportFilter $filter, $sources): array
    {
        $byZone = $page->listBy !== 'site';
        $judge = $this->judgeContext($filter);
        $reach = $this->reachByPlace($filter);
        $rows = $this->places($filter, $byZone, withAddresses: true);
        $campaigns = TrafficCampaign::query()->whereKey(collect($rows)->pluck('campaign_id')->filter()->unique()->all())->get(['id', 'name', 'external_id'])->keyBy('id');
        $out = [];

        foreach ($rows as $row) {
            $block = $judge['blocks']->get($row['key']);
            $judged = $this->judgeRow($judge, $row['key'], $row['source_id'], $row['figures'], $row['campaign_id'], $row['first_at'], $block === null);
            $state = $judged['state'];
            $campaign = $row['campaign_id'] !== null ? $campaigns->get($row['campaign_id']) : null;
            $out[] = [
                'key' => $row['key'],
                'ref' => $row['ref'],
                'level' => $row['level'],
                'site' => $row['site'],
                'source' => (string) ($sources->firstWhere('id', $row['source_id'])->name ?? 'Source #'.$row['source_id']),
                'source_id' => $row['source_id'],
                'campaign' => $campaign !== null ? ($campaign->name ?? 'Campaign '.$campaign->external_id) : ($row['campaigns'] > 1 ? 'In '.$row['campaigns'].' campaigns' : null),
                'state' => $state,
                'early' => $state === 'early' && $block === null ? $this->earlyNote($judge, $row['source_id'], $row['campaign_id'], $row['figures']) : null,
                'blocked' => $block !== null,
                'flag' => $this->flag($judged, $block),
                'figures' => $row['figures'],
                'reach' => $reach[$row['key']] ?? null,
            ];
        }

        // A block in force not listed by its clicks is listed too, with no figures, so Blocked lists what a source's
        // Blocking pane counts: one that sent no click in the period — a working block stops them — and, by zone, a
        // site block whose clicks show as its zones, a row of its own beside theirs. Blocks are a source's, so only
        // while the filters go no narrower than the source.
        if (array_diff_key($filter->where, array_flip(['source_id', 'channel'])) === [] && ($filter->where['channel'] ?? 'network') === 'network') {
            $shown = array_flip(array_column($out, 'key'));

            foreach ($judge['blocks'] as $key => $block) {
                if (isset($shown[$key]) || $block->network_state === TrafficBlockNetworkState::Removing
                    || (array_key_exists('source_id', $filter->where) && $filter->where['source_id'] !== (int) $block->source_id)
                    || ($block->level === TrafficBlockLevel::Zone && ! $byZone)) {
                    continue;
                }

                $out[] = [
                    'key' => (string) $key,
                    'ref' => (string) $block->ref,
                    'level' => $block->level->value,
                    'site' => null,
                    'source' => (string) ($sources->firstWhere('id', $block->source_id)->name ?? 'Source #'.$block->source_id),
                    'source_id' => (int) $block->source_id,
                    'campaign' => null,
                    'state' => 'early',
                    'early' => null,
                    'blocked' => true,
                    'flag' => $this->flag(['state' => 'early', 'tip' => null, 'from' => null], $block),
                    'figures' => new ReportFigures,
                    'reach' => null,
                ];
            }
        }

        return $out;
    }

    /**
     * How far a site too early to judge the money still has to go: its spend
     * against so many times its target price of an account, or its clicks
     * against the rule's; null once that is reached and the money only waits
     * for the worth.
     *
     * @param  array{general: array<string, int|string>, rules: array<int, array<string, int|string>>, startRates: array<int, float|null>, openRates: array<int, float|null>, campaignTargets: array<int, string>, sourceTargets: array<int, string|null>, worthKnown: bool, blocks: Collection<string, TrafficBlock>, firstClicks: array<string, CarbonImmutable>, windowFrom: CarbonImmutable, now: CarbonImmutable, reachHints: array<string, array{tip: string, replacesProfit: bool}>}  $judge
     */
    private function earlyNote(array $judge, int $sourceId, ?int $campaignId, ReportFigures $figures): ?string
    {
        $rule = $judge['rules'][$sourceId] ?? $judge['general'];
        $target = BlockRule::target($campaignId !== null ? ($judge['campaignTargets'][$campaignId] ?? null) : null, $judge['sourceTargets'][$sourceId] ?? null);
        $needed = BlockJudge::spendNeeded($rule, $target['amount'] ?? null);
        $spend = TrafficMoney::of($figures->spend);

        return match (true) {
            $needed !== null => $spend->isLessThan($needed) ? 'too early: '.TrafficMoney::format($spend).' of '.TrafficMoney::format($needed).' spent' : null,
            $figures->visits < (int) $rule['block_min_clicks'] && $spend->isLessThan(TrafficMoney::of($rule['block_min_spend'])) => 'too early: '.number_format($figures->visits).' of '.number_format((int) $rule['block_min_clicks']).' clicks',
            default => null,
        };
    }

    /**
     * The networks' sites or zones on the filters, keyed as their blocks —
     * "source:site:ref", "source:zone:ref" — as the block rule judges them:
     * each the visits came from, a zone where the network names zones and
     * the list is by zone, else its site; and each Low reach picks out that
     * sent no visit at all — at least traffic.reach.min_impressions
     * impressions over the period's closed days, none of them reached, the
     * worst reach there is, which the visits alone never show — with no
     * figures and the campaigns of the network's figures, so it is judged
     * (low_reach, judgeRow()), counted and blocked like any other. Such a
     * zone only by zone, and such a site only where none of its zones sent a
     * visit either; none on filters the impressions are never split by, nor
     * of a source whose closed days reached nothing at all, which leaves no
     * share to fall short of. The rest stay in the totals of On the way here
     * alone.
     *
     * @return list<array{key: string, level: string, ref: string, site: string|null, source_id: int, campaign_id: int|null, campaigns: int, first_at: CarbonImmutable|null, figures: ReportFigures}>
     */
    private function places(TrafficReportFilter $filter, bool $byZone, bool $withAddresses = false): array
    {
        $places = [];
        $listed = [];
        // Only the list shows repeat addresses: the rule's counts never read them, so they skip the extra grouped read.
        $addresses = $withAddresses ? $this->reports->repeatAddresses($filter, $byZone) : [];

        foreach ($this->reports->zones($filter, $byZone) as $row) {
            if ($withAddresses) {
                $address = $addresses[TrafficReportQuery::placeKey($row['source_id'], $row['site_ref'], $row['zone_ref'])] ?? null;
                $row['figures'] = $row['figures']->withAddresses($address['addressed'] ?? 0, $address['repeated'] ?? 0);
            }

            $zone = $byZone && $row['zone_ref'] !== null && $row['zone_ref'] !== '';
            $ref = (string) ($zone ? $row['zone_ref'] : $row['site_ref']);

            // A site whose clicks show as its zones has visits all the same.
            if ((string) $row['site_ref'] !== '') {
                $listed[$row['source_id'].':site:'.$row['site_ref']] = true;
            }

            if ($ref === '') {
                continue;
            }

            $level = $zone ? 'zone' : 'site';
            $key = $row['source_id'].':'.$level.':'.$ref;
            $listed[$key] = true;
            $places[] = ['key' => $key, 'level' => $level, 'ref' => $ref, 'site' => $zone ? (string) $row['site_ref'] : null, 'source_id' => $row['source_id'],
                'campaign_id' => $row['campaign_id'], 'campaigns' => $row['campaigns'], 'first_at' => $row['first_at'], 'figures' => $row['figures']];
        }

        // Only where Low reach applies: a source none of whose impressions reached RateGuru — a link without the site macro — has no share to fall short of.
        $hints = $this->judgeContext($filter)['reachHints'];
        $tally = $hints === [] ? null : $this->reachTally($filter);

        if ($tally === null) {
            return $places;
        }

        [$sourceId, $campaignId] = self::reachScope($filter);
        $names = $tally->placeNames($sourceId, $campaignId);

        foreach (array_keys($hints) as $key) {
            [$source, $level, $ref] = explode(':', $key, 3);

            if (isset($listed[$key]) || ($level === 'zone' && ! $byZone)) {
                continue;
            }

            $campaigns = $names[$key]['campaigns'] ?? [];
            $places[] = ['key' => $key, 'level' => $level, 'ref' => $ref, 'site' => $level === 'zone' ? ($names[$key]['site'] ?? null) : null, 'source_id' => (int) $source,
                'campaign_id' => count($campaigns) === 1 ? $campaigns[0] : null, 'campaigns' => count($campaigns), 'first_at' => null, 'figures' => new ReportFigures];
        }

        return $places;
    }

    /** How many sites and zones the block rule picks out on the filters, not blocked yet. */
    private function worthBlocking(TrafficReportFilter $filter, $sources): int
    {
        $count = 0;
        $judge = $this->judgeContext($filter);

        foreach ($this->places($filter, true) as $row) {
            $state = $this->judgeRow($judge, $row['key'], $row['source_id'], $row['figures'], $row['campaign_id'], $row['first_at'])['state'];

            if (in_array($state, SiteZoneList::WORTH_BLOCKING, true) && ! $judge['blocks']->has($row['key'])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * What the page needs to tick sites and zones: the keys on the page on
     * screen and every key the list matches, each one that can be ticked,
     * both from one reading of the rows.
     *
     * @return array{selectable: list<string>, matchingSelectable: list<string>}
     */
    public function siteSelection(TrafficSourcesPage $page, TrafficReportFilter $filter): array
    {
        $sources = TrafficSource::query()->get(['id', 'name']);
        $read = $this->siteRows($page, $filter, $sources);
        $list = $this->siteList($page, $filter, $sources, $read);
        $rows = collect($read)->keyBy('key');

        return [
            'selectable' => $list['selectable'],
            'matchingSelectable' => collect($list['matching'])->filter(fn (string $key): bool => $page->segment === 'blocked' ? (bool) ($rows[$key]['blocked'] ?? false) : ! ($rows[$key]['blocked'] ?? true))->values()->all(),
        ];
    }

    /**
     * Campaigns, as the reference draws it: the newest campaign that came in
     * by itself, waiting to be set up; how a campaign gets here; the link to
     * paste for a source and a game, with samples to check; the latest
     * clicks; the networks' campaigns by source — targeting, stage, price
     * and budget, block lists and the period's figures — in their stages;
     * and the banners and links bought at a fixed price, against what they
     * brought.
     *
     * @return array<string, mixed>
     */
    private function campaigns(TrafficSourcesPage $page, TrafficReportFilter $filter, $sources): array
    {
        $figures = $this->reports->byCampaign($filter);
        $games = Game::query()->orderBy('title')->get(['id', 'slug', 'title']);
        $ofSource = fn () => TrafficCampaign::query()->when(is_numeric($page->source), fn ($query) => $query->where('source_id', (int) $page->source));
        $stageOf = fn (TrafficCampaign $campaign): string => match (true) {
            $campaign->status === TrafficCampaignStatus::Archived => 'archived',
            $campaign->status === TrafficCampaignStatus::New => 'new',
            default => $campaign->stage->value ?? 'test',
        };
        // Every campaign of the source in its stage, however many; then the newest of the stage chosen, so many at most.
        $byStage = $ofSource()->get(['status', 'stage'])->countBy($stageOf);
        $counts = ['all' => $byStage->except('archived')->sum()] + collect(['test', 'optimise', 'scale', 'paused', 'new', 'archived'])->mapWithKeys(fn (string $stage): array => [$stage => (int) $byStage->get($stage, 0)])->all();
        $shown = $ofSource()
            ->tap(fn ($query) => match ($page->stage) {
                'all' => $query->where('status', '!=', TrafficCampaignStatus::Archived),
                'archived' => $query->where('status', TrafficCampaignStatus::Archived),
                'new' => $query->where('status', TrafficCampaignStatus::New),
                // A running campaign given no stage yet is testing.
                default => $query->whereNotIn('status', [TrafficCampaignStatus::New, TrafficCampaignStatus::Archived])
                    ->where(fn ($stage) => $stage->where('stage', $page->stage)->when($page->stage === 'test', fn ($test) => $test->orWhereNull('stage'))),
            })
            ->orderByDesc('first_click_at')
            ->orderByDesc('id')
            ->limit(self::CAMPAIGN_CAP)
            ->get();
        $since = TrafficCampaignStagePeriod::query()->whereIn('campaign_id', $shown->pluck('id'))->whereNull('ended_at')->pluck('started_at', 'campaign_id');
        $blocked = TrafficBlock::query()->pluck('source_id')->countBy();
        $toBlock = $this->toBlockByCampaign($filter);
        $connections = TrafficApiConnection::query()->get()->keyBy('source_id');
        $countries = Countries::all();
        $networks = $sources->where('type', TrafficSourceType::Network)->values();
        $placements = $sources->where('type', TrafficSourceType::Placement)->values();
        $linkSource = $sources->firstWhere('id', (int) $page->linkSource) ?? $networks->first() ?? $placements->first();
        $linkGame = $games->firstWhere('id', (int) $page->linkGame) ?? $games->first();
        $link = $linkSource === null || $linkGame === null ? null : ($linkSource->type === TrafficSourceType::Network
            ? TrackingLinkBuilder::forNetwork(route('games.play', ['slug' => $linkGame->slug]), $linkSource->link_key, $linkSource->link_params)
            : TrackingLinkBuilder::forPlacement(route('games.play', ['slug' => $linkGame->slug]), $linkSource->link_key, $linkSource->creatives[0] ?? null));
        $new = $ofSource()->where('status', TrafficCampaignStatus::New)->orderByDesc('first_click_at')->orderByDesc('id')->first();

        return [
            'newCampaign' => $new === null ? null : [
                'id' => $new->id,
                'source' => $sources->firstWhere('id', $new->source_id)->name ?? 'A network',
                'external' => $new->external_id,
                'name' => $new->name,
                // Its name, targeting, bid and budget already read from the network's API: only its stage is left to choose.
                'synced' => $new->synced_at !== null,
                'visits' => ($figures[$new->id] ?? new ReportFigures)->visits,
                'since' => $new->first_click_at,
                'game' => $games->firstWhere('id', $new->game_id)->title ?? null,
            ],
            'linkSources' => $sources->whereIn('type', [TrafficSourceType::Network, TrafficSourceType::Placement])->mapWithKeys(fn (TrafficSource $source): array => [(string) $source->id => $source->name])->all(),
            'linkSourceId' => $linkSource === null ? '' : (string) $linkSource->id,
            'linkSourceName' => $linkSource instanceof TrafficSource ? $linkSource->name : null,
            'linkKey' => $linkSource instanceof TrafficSource ? $linkSource->link_key : null,
            'games' => $games->mapWithKeys(fn (Game $game): array => [(string) $game->id => $game->title])->all(),
            'linkGameId' => $linkGame === null ? '' : (string) $linkGame->id,
            'link' => $link,
            'samples' => $link !== null && $linkSource instanceof TrafficSource && $linkSource->type === TrafficSourceType::Network ? $this->linkSamples($link, $linkSource) : [],
            'inspection' => trim($page->checkLink) === '' ? null : LinkInspector::inspect(
                $page->checkLink,
                (string) parse_url((string) config('app.url'), PHP_URL_HOST),
                fn (string $key): ?array => ($found = $sources->firstWhere('link_key', $key)) === null ? null : ['id' => $found->id, 'name' => $found->name, 'link_params' => $found->link_params, 'api_spend' => $found->spend_mode->value === 'api'],
                fn (string $slug): ?string => $games->firstWhere('slug', $slug)?->title,
                fn (int $sourceId, string $id): ?array => ($found = TrafficCampaign::query()->where('source_id', $sourceId)->where('external_id', $id)->first()) === null ? null : ['name' => $found->name, 'archived' => $found->status === TrafficCampaignStatus::Archived],
            ),
            'latestClicks' => $this->latestClicks($sources),
            'stageCounts' => $counts,
            'campaignGroups' => $networks->filter(fn (TrafficSource $source): bool => $shown->contains('source_id', $source->id))->map(fn (TrafficSource $source): array => [
                'source' => $source->name,
                'note' => ($connection = $connections->get($source->id)) !== null && $connection->status === TrafficConnectionStatus::Connected
                    ? 'Campaigns, targeting, bids and budgets read from the '.$source->name.' API'.($connection->campaigns_synced_at !== null ? ' · synced '.$connection->campaigns_synced_at->format('j M H:i') : '')
                    : 'No API connection for '.$source->name.': targeting, bid and budget typed in here',
                'rows' => $shown->where('source_id', $source->id)->map(fn (TrafficCampaign $campaign): array => [
                    'id' => $campaign->id,
                    // Its name in the network once the API read it, whatever its stage.
                    'name' => $campaign->name ?? ($campaign->status === TrafficCampaignStatus::New ? 'New campaign ' : 'Campaign ').$campaign->external_id,
                    'new' => $campaign->status === TrafficCampaignStatus::New,
                    'synced' => $campaign->synced_at !== null,
                    'sub' => implode(' · ', array_filter([$source->name, $campaign->external_id, $games->firstWhere('id', $campaign->game_id)?->title])),
                    'countries' => $campaign->countries === null ? null : collect($campaign->countries)->map(fn (string $code): string => $countries[$code] ?? $code)->take(3)->implode(', ').(count($campaign->countries) > 3 ? ' +'.(count($campaign->countries) - 3) : ''),
                    'devices' => match (true) {
                        $campaign->devices === null || count($campaign->devices) !== 1 => 'All devices',
                        $campaign->devices[0] === 'mobile' => 'Phones only',
                        default => 'Desktop only',
                    },
                    'format' => $campaign->format,
                    'stage' => $stageOf($campaign),
                    'since' => $campaign->status === TrafficCampaignStatus::New ? $campaign->first_click_at : ($since[$campaign->id] ?? $campaign->first_click_at),
                    'price' => $this->priceLine($campaign),
                    'budget' => implode(' · ', array_filter([
                        $campaign->daily_budget !== null ? TrafficMoney::format($campaign->daily_budget, 0).' a day' : null,
                        match ($campaign->spend_mode ?? $source->spend_mode) {
                            TrafficSpendMode::Api => 'spend via API',
                            TrafficSpendMode::ClickCost => 'click price',
                            TrafficSpendMode::Rate => 'a rate per click',
                            TrafficSpendMode::Price => 'its own price',
                            TrafficSpendMode::Manual => 'typed in',
                            default => null,
                        },
                    ])),
                    'blocked' => (int) ($blocked[$source->id] ?? 0),
                    'whitelist' => $campaign->whitelist_name,
                    'figures' => $figures[$campaign->id] ?? new ReportFigures,
                    'target' => $campaign->target_cpa ?? $source->target_cpa,
                    'targetFrom' => $campaign->target_cpa !== null ? 'campaign' : 'source',
                    'toBlock' => $toBlock[$campaign->id] ?? 0,
                ])->values()->all(),
            ])->values()->all(),
            'archivedHidden' => $page->stage === 'archived' ? 0 : $counts['archived'],
            // The older campaigns of the stage chosen past the newest shown.
            'campaignsMore' => max(0, $counts[$page->stage] - $shown->count()),
            'campaignCap' => self::CAMPAIGN_CAP,
            'placements' => $this->placementRows($placements, $filter),
        ];
    }

    /**
     * Samples to check against the link: the link itself, a real click from
     * it — every token filled in — a click from a campaign RateGuru hasn't
     * seen, and a click that lost its source key.
     *
     * @return array<string, string>
     */
    private function linkSamples(string $link, TrafficSource $source): array
    {
        $values = ['click' => substr(hash('sha256', $link), 0, 24), 'campaign' => '5521904', 'site' => '47494', 'zone' => '63528', 'creative' => '1180009', 'cost' => '0.0151'];
        $fill = function (array $overrides) use ($link, $source, $values): string {
            $filled = $link;

            foreach ($source->link_params as $param) {
                if ($param['token'] !== '') {
                    $filled = str_replace($param['param'].'='.$param['token'], $param['param'].'='.rawurlencode((string) ($overrides[$param['kind']] ?? $values[$param['kind']] ?? 'x')), $filled);
                }
            }

            return $filled;
        };

        return [
            $source->name.' link' => $link,
            'A real '.$source->name.' click' => $fill([]),
            'A click from a new campaign' => $fill(['campaign' => '9'.random_int(100000, 999999)]),
            'A broken link' => (string) preg_replace('/([?&])rg_src=[^&]*&?/', '$1', $fill([])),
        ];
    }

    /**
     * The newest paid clicks, as they came in: source and campaign, the
     * query the network sent, device and country.
     *
     * @return list<array{at: CarbonImmutable, name: string, query: string, where: string}>
     */
    private function latestClicks($sources): array
    {
        $visits = TrafficVisit::query()->whereNotNull('source_id')->orderByDesc('id')->limit(7)->get(['id', 'source_id', 'campaign_id', 'site_ref', 'zone_ref', 'creative_ref', 'cost', 'device', 'country', 'arrived_at']);
        $campaigns = TrafficCampaign::query()->whereKey($visits->pluck('campaign_id')->filter()->unique()->all())->get(['id', 'name', 'external_id'])->keyBy('id');
        $clickIds = TrafficVisitDetail::query()->whereIn('visit_id', $visits->pluck('id'))->pluck('click_id', 'visit_id');

        return $visits->map(function (TrafficVisit $visit) use ($sources, $campaigns, $clickIds): array {
            $source = $sources->firstWhere('id', $visit->source_id);
            $campaign = $visit->campaign_id === null ? null : $campaigns->get($visit->campaign_id);

            return [
                'at' => $visit->arrived_at,
                'name' => implode(' · ', array_filter([$source?->name, $campaign === null ? null : ($campaign->name ?? 'Campaign '.$campaign->external_id)])),
                'query' => '?'.http_build_query(array_filter([
                    'rg_src' => $source?->link_key,
                    'cid' => $clickIds[$visit->id] ?? null,
                    'campaign' => $campaign?->external_id,
                    'site' => $visit->site_ref,
                    'zone' => $visit->zone_ref,
                    'creative' => $visit->creative_ref,
                    'cost' => $visit->cost === null ? null : rtrim(rtrim((string) $visit->cost, '0'), '.'),
                ], fn ($value): bool => $value !== null && $value !== '')),
                'where' => ucfirst($visit->device->value).($visit->country !== null ? ' · '.$visit->country : ''),
            ];
        })->all();
    }

    /** A campaign's price per click or per thousand, mobile then desktop where they differ. */
    private function priceLine(TrafficCampaign $campaign): ?string
    {
        if ($campaign->bid_mobile === null && $campaign->bid_desktop === null) {
            return null;
        }

        $cpm = $campaign->bid_model === 'cpm';
        $format = fn (?string $bid): string => $bid === null ? '—' : TrafficMoney::format($bid, $cpm ? 2 : 4);
        $pair = $campaign->bid_mobile === $campaign->bid_desktop || $campaign->bid_desktop === null ? $format($campaign->bid_mobile) : $format($campaign->bid_mobile).' / '.$format($campaign->bid_desktop);

        return $pair.($cpm ? ' CPM' : '');
    }

    /**
     * How many sites and zones the block rule picks out in each campaign, on
     * the filters, where a site came through one campaign only — by its
     * visits, or by the network's figures for one that sent none (places()).
     *
     * @return array<int, int> by campaign
     */
    private function toBlockByCampaign(TrafficReportFilter $filter): array
    {
        $judge = $this->judgeContext($filter);
        $counts = [];

        foreach ($this->places($filter, true) as $row) {
            if ($row['campaign_id'] === null) {
                continue;
            }

            $state = $this->judgeRow($judge, $row['key'], $row['source_id'], $row['figures'], $row['campaign_id'], $row['first_at'])['state'];

            if (in_array($state, SiteZoneList::WORTH_BLOCKING, true) && ! $judge['blocks']->has($row['key'])) {
                $counts[$row['campaign_id']] = ($counts[$row['campaign_id']] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Banners and links bought at a fixed price: what they brought in the
     * period against what they cost.
     *
     * @return list<array<string, mixed>>
     */
    private function placementRows($placements, TrafficReportFilter $filter): array
    {
        if ($placements->isEmpty()) {
            return [];
        }

        $bySource = collect($this->reports->level($filter, TrafficDimension::Source))->keyBy(fn (array $group): string => (string) ($group['keys']['source_id'] ?? ''));
        $unallocated = collect($this->spend->unallocated($filter->fromDay, $filter->toDay))->groupBy('source_id');

        return $placements->map(function (TrafficSource $source) use ($bySource, $unallocated): array {
            $figures = $bySource->get((string) $source->id)['figures'] ?? new ReportFigures;
            $paid = TrafficMoney::of($figures->spend)->plus(TrafficMoney::of($unallocated->get($source->id)?->sum(fn (array $row): float => (float) $row['amount']) ?? 0));
            $value = $figures->value();

            return [
                'id' => $source->id,
                'name' => $source->name,
                'sub' => implode(' · ', array_filter([
                    $source->place_site,
                    $source->fixed_amount !== null ? TrafficMoney::format($source->fixed_amount, 0).' '.($source->fixed_period?->value === 'once' ? 'once' : 'a '.($source->fixed_period->value ?? 'month')) : null,
                    $source->fixed_from !== null ? CarbonImmutable::parse($source->fixed_from)->format('j M').($source->fixed_to !== null ? '–'.CarbonImmutable::parse($source->fixed_to)->format('j M') : '') : null,
                    $source->status->value === 'ended' ? 'Ended' : 'Active',
                ])),
                'visits' => $figures->visits,
                'value' => (string) $value,
                'paid' => (string) $paid,
                'ratio' => $paid->isZero() ? null : (float) (string) $value->dividedBy($paid, 4, RoundingMode::HalfUp),
            ];
        })->all();
    }

    /**
     * Value, as the reference draws it: the prices of the outcomes — the
     * account, its bonuses, a sign-in to an account a visitor already had —
     * with how often they came in the period and what an account brings on
     * average; accounts by source, every paid source with its own account
     * price, if any, beside what its accounts brought, against its target;
     * who counts as new; the country groups with their share of visitors and
     * what a finished game is worth in each; what a new visitor is worth,
     * step by step; how the chances are worked out; and the history of the
     * prices. Wherever every source counts at once, the sources' own account
     * prices are mixed in as their accounts came.
     *
     * @return array<string, mixed>
     */
    private function value(TrafficReportFilter $filter, $sources): array
    {
        $run = TrafficChanceRun::query()->where('status', 'finished')->orderByDesc('id')->first();
        $site = $run === null ? collect() : TrafficStepChance::query()->where('run_id', $run->id)->where('slice_key', '*')->get()->keyBy('step');
        $summary = $this->reports->summary($filter);
        $sum = fn (iterable $rows): ReportFigures => collect($rows)->reduce(fn (ReportFigures $total, array $row): ReportFigures => $total->plus($row['figures']), new ReportFigures);
        $total = $sum($summary);
        $paidRows = array_filter($summary, fn (array $row): bool => in_array($row['channel'], ['network', 'placement'], true));
        $paid = $sum($paidRows);
        $internal = $sum(array_filter($summary, fn (array $row): bool => $row['channel'] === 'internal'));
        $set = TrafficPriceSet::current();
        $prices = $set->worth();
        $postRate = isset($site['post_bonus']) ? (float) $site['post_bonus']->chance : ($total->accounts > 0 ? $total->posted / $total->accounts : 0.0);
        $returnRate = isset($site['returned_bonus']) ? (float) $site['returned_bonus']->chance : ($total->accounts > 0 ? $total->returned / $total->accounts : 0.0);
        $groups = collect($set->country_groups);
        $byGroup = $this->reports->byCountryGroup($filter);
        $opened = array_sum(array_map(fn (ReportFigures $f): int => $f->opened, $byGroup));
        // Every group at once, as the period's visitors are spread over them; a full share before any came.
        $mix = $opened === 0 ? 100 : (int) round(array_sum(array_map(fn (string $key, ReportFigures $f): int => $f->opened * (int) ($groups->firstWhere('key', $key)['percent'] ?? 100), array_keys($byGroup), $byGroup)) / $opened);
        // What the accounts brought, each at its own group's share and its source's account price — sign-ins to accounts visitors already had
        // left out; for rows with none yet, what one is expected to bring at the prices given, in the mix of groups.
        $brings = fn (ReportFigures $f, WorthPrices $at): BigDecimal => $f->accounts > 0
            ? TrafficMoney::of($f->valueAccounts)->dividedBy($f->accounts, TrafficMoney::SCALE, RoundingMode::HalfUp)
            : $at->account($postRate, $returnRate, $mix);
        $worth = app(TrafficWorthQuery::class);
        // A slice of every source counts the sources' own account prices as its accounts came from them.
        $sliceWorth = [];
        $stepWorth = function (string $step, ?string $device, ?string $group) use ($worth, $run, $prices, $groups, $mix, &$sliceWorth): string {
            $slice = new Slice(device: $device, group: $group);
            $sliceWorth[$slice->key()] ??= [$worth->pricesFor($prices, $run, $slice), $worth->table($run, $slice)];

            return (string) StepWorth::of($slice, $step, $sliceWorth[$slice->key()][0], $group === null ? $mix : (int) ($groups->firstWhere('key', $group)['percent'] ?? 100), false, null, $sliceWorth[$slice->key()][1])['value'];
        };
        $bySource = collect($this->reports->level($filter, TrafficDimension::Source));
        $unpaid = $sum($bySource->filter(fn (array $group): bool => $group['keys']['source_id'] === null)->all());
        $newVisitors = fn (ReportFigures $f): int => max(0, $f->opened - $f->knownOpened);
        // Each paid source's new visitors in the period: the weight of its own account price where no account came to weigh it by.
        $visitorsBySource = $bySource->filter(fn (array $group): bool => $group['keys']['source_id'] !== null)
            ->mapWithKeys(fn (array $group): array => [(int) $group['keys']['source_id'] => $newVisitors($group['figures'])])->all();
        $own = $set->sourceAccountPrices();
        // Every paid source the filters admit, those without a visit in the period too, so each can be given an account price of its own.
        $paidSources = $sources->whereIn('type', [TrafficSourceType::Network, TrafficSourceType::Placement])
            ->filter(fn (TrafficSource $source): bool => (! array_key_exists('source_id', $filter->where) || $filter->where['source_id'] === (int) $source->id)
                && (! isset($filter->where['channel']) || $filter->where['channel'] === $source->type->value)
                && ! isset($filter->where['campaign_id']));
        $sourceGroups = $bySource->filter(fn (array $group): bool => $group['keys']['source_id'] !== null)->values();
        $seen = $sourceGroups->map(fn (array $group): int => (int) $group['keys']['source_id'])->all();
        $sourceGroups = $sourceGroups->concat($paidSources->reject(fn (TrafficSource $source): bool => in_array((int) $source->id, $seen, true))
            ->map(fn (TrafficSource $source): array => ['keys' => ['source_id' => (int) $source->id], 'figures' => new ReportFigures]));

        return [
            'run' => $run === null ? null : ['at' => $run->finished_at, 'visits' => $run->visits_scanned, 'accounts' => (int) ($site['opened']->slice_accounts ?? 0)],
            'outcomes' => ['accounts' => $total->accounts, 'posted' => $total->posted, 'returned' => $total->returned, 'logins' => array_sum(array_map(fn (array $row): int => $row['path']['login'] ?? 0, $summary))],
            'postRate' => $postRate,
            'returnRate' => $returnRate,
            'accountBrings' => (string) $brings($total, $prices->mixed($visitorsBySource, $newVisitors($total))),
            'sitePrice' => (string) $set->account_price,
            'ownPrices' => collect($own)->map(fn (string $price, int $id): array => ['name' => $sources->firstWhere('id', $id)->name ?? 'Source #'.$id, 'price' => $price])->values()->all(),
            'sourceRows' => $sourceGroups->map(function (array $group) use ($sources, $brings, $prices, $own): array {
                $id = (int) $group['keys']['source_id'];
                $source = $sources->firstWhere('id', $id);
                $f = $group['figures'];

                return [
                    'id' => $id,
                    'name' => $source->name ?? 'Source #'.$id,
                    'kind' => $source?->type === TrafficSourceType::Placement ? 'Banner or link' : 'Ad network',
                    'figures' => $f,
                    'brings' => (string) $brings($f, $prices->forSource($id)),
                    'target' => $source?->target_cpa,
                    // Only a source still on the books can be given an account price.
                    'priced' => $source !== null,
                    'ownPrice' => $own[$id] ?? null,
                ];
            })->all(),
            'paidRow' => ['figures' => $paid, 'brings' => (string) $brings($paid, $prices->mixed($visitorsBySource, $newVisitors($paid)))],
            'unpaidRow' => ['figures' => $unpaid, 'brings' => (string) $brings($unpaid, $prices->forSource(null))],
            'who' => [
                'known' => $total->knownOpened,
                'knownShare' => $total->opened > 0 ? $total->knownOpened / $total->opened : null,
                'paidKnown' => $paid->knownOpened,
                'paidKnownSpend' => $paid->knownSpend,
                'internalKnown' => $internal->opened > 0 ? $internal->knownOpened / $internal->opened : null,
            ],
            'groupRows' => $groups->map(fn (array $group): array => [
                'key' => $group['key'],
                'share' => $opened > 0 ? ($byGroup[$group['key']]->opened ?? 0) / $opened : null,
                'mobile' => $stepWorth('completed', 'mobile', $group['key']),
                'desktop' => $stepWorth('completed', 'desktop', $group['key']),
            ])->values()->all(),
            'countries' => Countries::all(),
            'visitorWorth' => $stepWorth('opened', null, null),
            'visitorChance' => isset($site['opened']) ? (float) $site['opened']->chance : null,
            'visitorRows' => collect(['opened' => 'Opened the game', 'started' => 'Started it', 'completed' => 'Finished it', 'email' => 'Left an email', 'signup' => 'Signed up, not confirmed', 'account' => 'Confirmed the account', 'login' => 'Signed in, had an account'])
                ->map(fn (string $label, string $step): array => ['label' => $label, 'mobile' => $stepWorth($step, 'mobile', null), 'desktop' => $stepWorth($step, 'desktop', null)])->values()->all(),
            'priceChanges' => TrafficPriceSet::query()->orderByDesc('id')->limit(5)->get()->map(fn (TrafficPriceSet $priceSet): array => [
                'summary' => $priceSet->summary ?? 'Account confirmed '.TrafficMoney::brief($priceSet->account_price).', Published a post +'.TrafficMoney::brief($priceSet->post_bonus).', Came back +'.TrafficMoney::brief($priceSet->returned_bonus).', Existing account signed in '.TrafficMoney::brief($priceSet->login_price)
                    .collect($priceSet->sourceAccountPrices())->map(fn (string $price, int $id): string => '; '.($sources->firstWhere('id', $id)->name ?? 'Source #'.$id).' account '.TrafficMoney::brief($price))->implode(''),
                'at' => $priceSet->created_at,
                'by' => $priceSet->created_by_user_id === null ? 'First prices' : (User::query()->whereKey($priceSet->created_by_user_id)->value('name') ?? 'An administrator'),
            ])->all(),
        ];
    }

    /**
     * Spend, as the reference draws it: the ways a source's spend is known
     * and which sources use each; spend by source — how it is worked out,
     * what reports count, what the network itself says, how the source
     * stands — each opening its campaigns; and, for the source chosen, each
     * day: its clicks, the impressions the network billed and how many of
     * them reached RateGuru, what their links said they cost, the network's
     * own figure and what reports count, with a day corrected or a file
     * taken.
     *
     * @return array<string, mixed>
     */
    private function spendTab(TrafficSourcesPage $page, TrafficReportFilter $filter, $sources): array
    {
        $paid = $sources->whereIn('type', [TrafficSourceType::Network, TrafficSourceType::Placement])->values()->loadCount('campaigns');
        // On a game, device, country or campaign the visits are that part of the source, and the network's bill and the spend no visit took are the whole of it.
        $narrowed = $this->narrowsVisits($filter);
        $unallocated = $narrowed ? collect() : collect($this->spend->unallocated($filter->fromDay, $filter->toDay));
        $bySource = collect($this->reports->level($filter, TrafficDimension::Source))->keyBy(fn (array $group): string => (string) ($group['keys']['source_id'] ?? ''));
        // What the network itself says, one origin a source's day as the day is counted: its file where one was taken, else its API.
        $network = TrafficSpendDay::query()->whereBetween('network_day', [$filter->fromDay, $filter->toDay])->whereIn('origin', [TrafficSpendOrigin::Api, TrafficSpendOrigin::Csv])->get(['source_id', 'campaign_id', 'origin', 'network_day', 'amount'])
            ->groupBy(fn (TrafficSpendDay $fact): string => $fact->source_id.':'.$fact->network_day)
            ->flatMap(fn (Collection $day): Collection => $day->contains('origin', TrafficSpendOrigin::Csv) ? $day->where('origin', TrafficSpendOrigin::Csv) : $day);
        $campaignFigures = $this->reports->byCampaign($filter);
        $connections = TrafficApiConnection::query()->get()->keyBy('source_id');
        $users = fn (array $modes): array => $paid->filter(fn (TrafficSource $source): bool => in_array($source->spend_mode, $modes, true))->pluck('name')->all();
        $chosen = $paid->firstWhere('id', (int) $page->spendSource);
        $days = $chosen === null ? [] : $this->spend->days((int) $chosen->id, $filter->fromDay, $filter->toDay);
        $today = CarbonImmutable::now($chosen->reporting_timezone ?? 'UTC')->toDateString();
        // The impressions the network billed each day, and how many reached RateGuru, of every campaign.
        $reach = $chosen === null ? [] : ($this->reachTally(new TrafficReportFilter($filter->fromDay, $filter->toDay, ['source_id' => (int) $chosen->id]))?->days((int) $chosen->id) ?? []);

        return [
            'methods' => [
                ['icon' => 'refresh-cw', 'name' => 'Network API', 'text' => site_name().' asks the network for its spend per campaign, site, zone and day, every five minutes. Exactly what the network bills, as soon as the network has it.', 'users' => $users([TrafficSpendMode::Api])],
                ['icon' => 'link', 'name' => 'Price in each click', 'text' => 'The network puts the price of every click into the link. Exact per click, available at once, no typing.', 'users' => $users([TrafficSpendMode::ClickCost])],
                ['icon' => 'smartphone', 'name' => 'Rate you set', 'text' => 'You type the price you bid, per device. Clicks × rate. An estimate: good enough to compare zones, not to match the invoice.', 'users' => $users([TrafficSpendMode::Rate])],
                ['icon' => 'calendar', 'name' => 'Fixed price', 'text' => 'A set amount for a day, a month or once, spread evenly over the days and split across visits.', 'users' => $users([TrafficSpendMode::Fixed])],
                ['icon' => 'file', 'name' => 'Typed in or imported', 'text' => 'Type the spend per campaign and day, or import the network’s CSV export. It replaces any estimate for those days.', 'users' => $users([TrafficSpendMode::Manual])],
            ],
            'spendRows' => $paid->map(function (TrafficSource $source) use ($bySource, $unallocated, $narrowed, $network, $campaignFigures, $connections, $page): array {
                $figures = $bySource->get((string) $source->id)['figures'] ?? new ReportFigures;
                $without = $unallocated->where('source_id', $source->id)->sum(fn (array $row): float => (float) $row['amount']);
                $reported = TrafficMoney::of($figures->spend)->plus(TrafficMoney::of($without));
                $networkFacts = $network->where('source_id', $source->id);
                $networkSum = $networkFacts->sum(fn (TrafficSpendDay $fact): float => (float) $fact->amount);
                $connection = $connections->get($source->id);
                $open = in_array((int) $source->id, $page->spendOpen, true);
                // Its newest campaigns only for a source opened by campaign.
                $campaigns = $open ? TrafficCampaign::query()->where('source_id', $source->id)->orderByDesc('first_click_at')->orderByDesc('id')->limit(50)->get(['id', 'external_id', 'name', 'game_id']) : collect();

                return [
                    'id' => $source->id,
                    'name' => $source->name,
                    'kind' => $source->type === TrafficSourceType::Placement ? 'Banner or link' : 'Ad network · '.$source->campaigns_count.' '.Str::plural('campaign', (int) $source->campaigns_count),
                    'how' => $this->spendHow($source),
                    'visits' => $figures->visits,
                    'reported' => (string) $reported,
                    'estimated' => $figures->spendEstimated,
                    'without' => (string) $without,
                    'narrowed' => $narrowed,
                    'network' => match (true) {
                        $source->spend_mode === TrafficSpendMode::Fixed => ['Agreed price', null],
                        $networkFacts->isEmpty() => ['Not checked', $source->spend_mode === TrafficSpendMode::Api ? 'Waiting for the first sync' : 'Import the network’s CSV to compare'],
                        $narrowed => [TrafficMoney::format($networkSum), 'For the whole source'],
                        default => [TrafficMoney::format($networkSum), $networkSum > 0 ? 'Differs by '.number_format(((float) (string) $reported - $networkSum) / $networkSum * 100, 1).'%' : null],
                    },
                    'status' => $this->spendStatus($source, $connection),
                    'api' => $connection !== null,
                    'open' => $open,
                    'campaigns' => $campaigns->map(fn (TrafficCampaign $campaign): array => [
                        'external' => $campaign->external_id,
                        'name' => $campaign->name ?? 'Campaign '.$campaign->external_id,
                        'figures' => $campaignFigures[$campaign->id] ?? new ReportFigures,
                        'network' => ($sum = $networkFacts->where('campaign_id', $campaign->id)->sum(fn (TrafficSpendDay $fact): float => (float) $fact->amount)) > 0 ? TrafficMoney::format($sum) : null,
                    ])->filter(fn (array $row): bool => $row['figures']->visits > 0 || $row['network'] !== null)->values()->all(),
                ];
            })->all(),
            'spendSourceName' => $chosen?->name,
            'spendSources' => $paid->mapWithKeys(fn (TrafficSource $source): array => [(string) $source->id => $source->name])->all(),
            'dayRows' => collect($days)->map(fn (array $row, string $day): array => [
                'day' => $day === $today ? 'Today' : ($day === CarbonImmutable::parse($today)->subDay()->toDateString() ? 'Yesterday' : CarbonImmutable::parse($day)->format('d M')),
                'date' => $day,
                'clicks' => $row['clicks'],
                'costs' => $row['costs'],
                // The network's file over its API, as the day is counted.
                'network' => $row['figures']['csv'] ?? $row['figures']['api'] ?? null,
                'networkOpen' => $day === $today,
                'origin' => $row['counted_origin'],
                'counted' => $row['counted'],
                'allocated' => $row['allocated'],
                'reach' => $reach[$day] ?? null,
            ])->values()->all(),
            // The days a correction is offered for, the last fourteen in the network's own time zone.
            'correctDay' => ['min' => CarbonImmutable::parse($today)->subDays(13)->toDateString(), 'max' => $today, 'timezone' => $chosen->reporting_timezone ?? 'UTC'],
        ];
    }

    /** Whether a filter keeps only a game's, a device's, a country's or a campaign's visits of a source. */
    private function narrowsVisits(TrafficReportFilter $filter): bool
    {
        return array_intersect_key($filter->where, array_flip(['game_id', 'device', 'country', 'campaign_id'])) !== [];
    }

    /** @return array{icon: string, text: string} how a source's spend is worked out, as Spend says it */
    private function spendHow(TrafficSource $source): array
    {
        return match ($source->spend_mode) {
            TrafficSpendMode::Api => ['icon' => 'refresh-cw', 'text' => 'Network API · today from '.($source->spend_estimate === 'rate' ? 'the rate' : 'the cost in each click')],
            TrafficSpendMode::ClickCost => ['icon' => 'link', 'text' => 'Price in each click · per '.($source->cost_unit->value === 'cpm' ? '1000 clicks' : 'click')],
            TrafficSpendMode::Rate => ['icon' => 'smartphone', 'text' => 'Rate you set · '.implode(' / ', array_filter([$source->rate_mobile !== null ? TrafficMoney::format($source->rate_mobile, 4).' mobile' : null, $source->rate_desktop !== null ? TrafficMoney::format($source->rate_desktop, 4).' desktop' : null]))],
            TrafficSpendMode::Fixed => ['icon' => 'calendar', 'text' => 'Fixed · '.($source->fixed_amount !== null ? TrafficMoney::format($source->fixed_amount, 0) : '—').' '.($source->fixed_period?->value === 'once' ? 'once' : 'a '.($source->fixed_period->value ?? 'month')).($source->fixed_from !== null ? ', '.CarbonImmutable::parse($source->fixed_from)->format('d M').($source->fixed_to !== null ? '–'.CarbonImmutable::parse($source->fixed_to)->format('d M') : '') : '')],
            TrafficSpendMode::Manual => ['icon' => 'file', 'text' => 'Typed in or imported'],
            default => ['icon' => 'circle', 'text' => 'Not counted'],
        };
    }

    /** @return array{tone: string, text: string} how a source's spend stands */
    private function spendStatus(TrafficSource $source, ?TrafficApiConnection $connection): array
    {
        return match (true) {
            $source->spend_mode === TrafficSpendMode::Api && $connection === null => ['tone' => 'danger', 'text' => 'No API connection yet'],
            $source->spend_mode === TrafficSpendMode::Api && $connection->status !== TrafficConnectionStatus::Connected => ['tone' => 'danger', 'text' => 'Needs attention: '.str_replace('_', ' ', (string) $connection->last_error)],
            $source->spend_mode === TrafficSpendMode::Api => ['tone' => 'success', 'text' => 'Synced '.($connection->last_synced_at?->format('j M H:i') ?? 'not yet').($connection->next_sync_at !== null ? ' · next at '.$connection->next_sync_at->format('H:i') : '')],
            $source->spend_mode === TrafficSpendMode::ClickCost => ['tone' => 'warning', 'text' => 'Exact per click, as the network passed it'],
            $source->spend_mode === TrafficSpendMode::Rate => ['tone' => 'warning', 'text' => 'An estimate from the rate'],
            $source->spend_mode === TrafficSpendMode::Fixed => $source->status->value === 'ended' || ($source->fixed_to !== null && $source->fixed_to < CarbonImmutable::now()->toDateString())
                ? ['tone' => 'neutral', 'text' => 'Ended · paid in full']
                : ['tone' => 'success', 'text' => 'Running · spread over the '.($source->fixed_period->value ?? 'month')],
            $source->spend_mode === TrafficSpendMode::Manual => ['tone' => 'neutral', 'text' => 'Typed in'],
            default => ['tone' => 'neutral', 'text' => 'Spend not counted'],
        };
    }

    /**
     * Sources, as the reference draws it: the ad networks, then the banners
     * and links, each with its preset, key, API, blocks and age, how its
     * postbacks stand and where its spend comes from, and its clicks over
     * the last 30 days; then the unpaid channels RateGuru recognises by
     * itself, with how each is recognised.
     *
     * @return array<string, mixed>
     */
    private function sourcesTab($sources): array
    {
        $sources->load('apiConnection');
        $month = TrafficReportFilter::lastDays(30, CarbonImmutable::now());
        $byGroup = collect($this->reports->level($month, TrafficDimension::Source))->keyBy(fn (array $group): string => $group['keys']['source_id'] !== null ? (string) $group['keys']['source_id'] : 'c:'.$group['keys']['channel']);
        $blocked = TrafficBlock::query()->pluck('source_id')->countBy();
        $rules = TrafficPostbackRule::query()->where('enabled', true)->get(['id', 'source_id', 'event']);
        $failed = TrafficPostback::query()->where('created_at', '>=', now()->subDays(7))->whereIn('status', [TrafficPostbackStatus::Pending, TrafficPostbackStatus::Failed])->where('attempts', '>', 0)->orderByDesc('id')->get(['id', 'source_id', 'rule_id', 'status', 'http_status', 'attempts', 'run_after', 'outcome']);
        $row = function (TrafficSource $source) use ($byGroup, $blocked, $rules, $failed): array {
            $connection = $source->apiConnection;
            $enabled = $rules->where('source_id', $source->id)->pluck('id');
            $ruleCount = $enabled->count();
            // A rule switched off keeps its postbacks, but no longer counts among the source's.
            $failing = $failed->where('source_id', $source->id)->whereIn('rule_id', $enabled);
            $failingRules = $failing->pluck('rule_id')->unique()->count();
            $last = $failing->first();

            return [
                'id' => $source->id,
                'name' => $source->name,
                'status' => $source->status->value,
                'sub' => implode(' · ', array_filter([
                    $source->preset_key !== null ? ($this->presets->find($source->preset_key)['name'] ?? $source->preset_key).' preset' : null,
                    $connection !== null ? ($connection->status === TrafficConnectionStatus::Connected ? 'API connected' : 'API needs attention') : null,
                    'key '.$source->link_key,
                    ($blocked[$source->id] ?? 0) > 0 ? $blocked[$source->id].' blocked' : null,
                    $source->type === TrafficSourceType::Placement && is_array($source->creatives) && $source->creatives !== [] ? count($source->creatives).' '.Str::plural('creative', count($source->creatives)) : null,
                    'since '.$source->created_at->format('j M Y'),
                ])),
                'postbacks' => match (true) {
                    $source->type === TrafficSourceType::Placement => ['icon' => 'minus', 'tone' => null, 'text' => 'No postbacks', 'sub' => 'A tracking link carries no click ID'],
                    $ruleCount === 0 => ['icon' => 'minus', 'tone' => null, 'text' => 'No postbacks', 'sub' => 'Add a rule in the editor to tell the network which clicks became players'],
                    $failingRules > 0 => ['icon' => 'circle-alert', 'tone' => 'bad', 'text' => $failingRules.' of '.$ruleCount.' '.Str::plural('postback', $ruleCount).' failing', 'sub' => implode(' · ', array_filter([$last?->http_status !== null ? 'HTTP '.$last->http_status : ($last?->outcome !== null ? str_replace('_', ' ', $last->outcome->value) : null), $last?->status === TrafficPostbackStatus::Pending ? 'retrying at '.$last->run_after->format('H:i') : null, $last !== null ? 'attempt '.$last->attempts.' of '.(count(PostbackSchedule::RETRY_AFTER) + 1) : null]))],
                    default => ['icon' => 'arrow-up-right', 'tone' => null, 'text' => $ruleCount.' '.Str::plural('postback', $ruleCount).' · delivering', 'sub' => $rules->where('source_id', $source->id)->map(fn ($rule): string => $rule->event === TrafficEvent::Goal ? 'Flow goal reached' : $rule->event->label())->unique()->implode(', ')],
                },
                'spend' => $this->spendHow($source),
                'spendStatus' => $this->spendStatus($source, $connection)['text'],
                'clicks' => ($byGroup->get((string) $source->id)['figures'] ?? new ReportFigures)->visits,
                'unit' => $source->type === TrafficSourceType::Placement ? 'visits' : 'clicks',
            ];
        };

        return [
            'networks' => $sources->where('type', TrafficSourceType::Network)->map($row)->values()->all(),
            'placementRows' => $sources->where('type', TrafficSourceType::Placement)->map($row)->values()->all(),
            'channels' => collect([
                'shared' => ['Shared results', 'share-2', 'Links players share from their result: the share page carries the result ID.'],
                'referral' => ['Other sites', 'globe', 'A link on another site, without a source key. Grouped by the referring site.'],
                'search' => ['Search', 'search', 'Referred by a search engine.'],
                'direct' => ['Direct', 'arrow-right', 'No referrer and no source key: typed in, bookmarks, apps that hide the referrer.'],
                'internal' => ['Inside '.site_name(), 'home', 'From the feed, a post page, another game or a notification on this site.'],
            ])->map(fn (array $channel, string $key): array => ['key' => $key, 'name' => $channel[0], 'icon' => $channel[1], 'how' => $channel[2], 'visits' => ($byGroup->get('c:'.$key)['figures'] ?? new ReportFigures)->visits])->values()->all(),
        ];
    }

    /**
     * Logs, as the reference draws them: postbacks — the milestone, how the
     * account came where it did, the click, the exact request and the
     * network's answer — milestones, clicks and blocking, newest first, fifty
     * to a page, with how many postbacks are failing, and each source by name.
     * A click, a milestone and a postback name their visitor by its journey
     * ID (journey()), by the row's id — a postback's in its own row — and a
     * click's spend says on hovering where it comes from (SpendHint).
     *
     * @return array<string, mixed>
     */
    private function logsTab(TrafficSourcesPage $page, $sources): array
    {
        $source = is_numeric($page->source) ? (int) $page->source : null;
        $logPage = match ($page->log) {
            'milestones' => $this->logs->milestones($source, $page->pageNumber),
            'clicks' => $this->logs->clicks($source, null, $page->pageNumber),
            'blocks' => $this->logs->blocks($source, $page->pageNumber),
            default => $this->logs->postbacks($source, $page->logFilter === 'failed' ? TrafficPostbackStatus::Failed->value : null, $page->pageNumber),
        };
        $failing = TrafficPostback::query()->where('status', TrafficPostbackStatus::Failed)->where('created_at', '>=', now()->subDays(7))->when($source !== null, fn ($query) => $query->where('source_id', $source))->count();
        $linked = GrowthAnalyticsPage::canAccess();
        $byId = $sources->keyBy('id');

        return [
            'labels' => ['source_id' => self::named($sources->pluck('name', 'id'))],
            'logPage' => $logPage,
            'logRows' => $page->log === 'postbacks' ? $this->postbackRows($logPage->items(), $linked) : [],
            'visitors' => match ($page->log) {
                'clicks' => collect($logPage->items())->mapWithKeys(fn (TrafficVisit $visit): array => [$visit->id => self::journey($visit->visitor_hash, $linked)])->all(),
                'milestones' => collect($logPage->items())->mapWithKeys(fn (TrafficConversion $conversion): array => [$conversion->id => self::journey($conversion->visit?->visitor_hash, $linked)])->all(),
                default => [],
            },
            // Where each click's spend comes from, on hovering it, read from the click and its source's spend mode — no read of its own.
            'spendHints' => $page->log === 'clicks'
                ? collect($logPage->items())->mapWithKeys(fn (TrafficVisit $visit): array => [$visit->id => SpendHint::of($visit->spend_basis, (bool) $visit->spend_estimated, $visit->cost, $visit->spend, $byId->get($visit->source_id)?->spend_mode)])->all()
                : [],
            'failing' => $failing,
            'tries' => count(PostbackSchedule::RETRY_AFTER) + 1,
        ];
    }

    /**
     * A log row's visitor as Growth → Analytics → Journeys names it, with the
     * address that looks it up there for an administrator who may open
     * Analytics — the ID alone for anyone else. None for a row whose visit
     * is gone. The address names the browser by as many digits as Journeys
     * tells apart browsers sharing an ID's, so it opens this visitor's
     * journey rather than a list to choose from.
     *
     * @return array{id: string, url: string|null}|null
     */
    private static function journey(?string $hash, bool $linked): ?array
    {
        if ($hash === null || $hash === '') {
            return null;
        }

        $who = JourneyTimeline::visitorId($hash, JourneyTimeline::LONG_DIGITS);

        return ['id' => JourneyTimeline::visitorId($hash), 'url' => $linked ? GrowthAnalyticsPage::getUrl(['tab' => 'journeys', 'who' => $who]) : null];
    }

    /**
     * Each postback of a page as the log shows it.
     *
     * @param  list<TrafficPostback>  $postbacks
     * @return list<array<string, mixed>>
     */
    private function postbackRows(array $postbacks, bool $linked): array
    {
        $conversions = TrafficConversion::query()->whereKey(array_map(fn (TrafficPostback $postback): int => (int) $postback->conversion_id, $postbacks))->with('visit.detail')->get()->keyBy('id');
        $places = ['game' => 'in the game', 'post' => 'on a post', 'feed' => 'in the feed', 'other' => 'elsewhere on the site'];
        $tries = count(PostbackSchedule::RETRY_AFTER) + 1;

        return array_map(function (TrafficPostback $postback) use ($conversions, $places, $tries, $linked): array {
            $conversion = $conversions->get($postback->conversion_id);
            $visit = $conversion?->visit;
            $clickId = (string) ($visit?->detail->click_id ?? '');
            $account = in_array($postback->event, [TrafficEvent::Account, TrafficEvent::Goal], true) && $visit?->account_at !== null;
            $after = $visit?->signup_after_seconds;

            return [
                'id' => $postback->id,
                'source' => (int) $postback->source_id,
                'at' => $postback->created_at,
                'milestone' => $postback->event === TrafficEvent::Goal ? 'Flow goal reached'.($conversion?->step !== null ? ' · '.StepLabels::name($conversion->step, 1) : '') : $postback->event->label(),
                'how' => $account && $visit->signup_place !== null ? 'Signed up '.$places[$visit->signup_place->value].($after !== null ? ' · '.self::duration($after).' after the click' : '') : null,
                'path' => $visit === null ? [] : collect([
                    'arrived_at' => 'Clicked', 'opened_at' => 'Opened the page', 'started_at' => 'Started the game', 'completed_at' => 'Finished it', 'signup_form_at' => 'Saw a sign-up form', 'signup_at' => 'Signed up', 'account_at' => 'Confirmed the account',
                ])->filter(fn (string $label, string $column): bool => $visit->{$column} !== null)->map(fn (string $label, string $column): array => ['label' => $label, 'at' => $visit->{$column}])->values()->all(),
                'visitor' => self::journey($visit?->visitor_hash, $linked),
                'clickId' => $clickId,
                'request' => 'GET '.($clickId !== '' ? PostbackUrl::withClickId($postback->url, $clickId) : $postback->url),
                'status' => $postback->status,
                'http' => $postback->http_status,
                'outcome' => $postback->outcome,
                'attempt' => match (true) {
                    $postback->status === TrafficPostbackStatus::Delivered => $postback->attempts <= 1 ? 'First try' : 'Try '.$postback->attempts.' of '.$tries,
                    $postback->status === TrafficPostbackStatus::Pending && $postback->attempts > 0 => 'Try '.$postback->attempts.' of '.$tries.' · retry at '.$postback->run_after->format('H:i'),
                    $postback->status === TrafficPostbackStatus::Pending => 'Waiting to be sent',
                    default => 'Try '.$postback->attempts.' of '.$tries,
                },
                'resendable' => in_array($postback->status, [TrafficPostbackStatus::Failed, TrafficPostbackStatus::Expired, TrafficPostbackStatus::Cancelled], true),
            ];
        }, $postbacks);
    }

    private static function duration(int $seconds): string
    {
        return match (true) {
            $seconds < 3600 => max(1, intdiv($seconds, 60)).' min',
            $seconds < 86400 => intdiv($seconds, 3600).' h '.intdiv($seconds % 3600, 60).' min',
            default => intdiv($seconds, 86400).' day '.intdiv($seconds % 86400, 3600).' h',
        };
    }

    /** @return array<string, mixed> */
    private function editor(TrafficSourcesPage $page, $sources): array
    {
        $source = is_numeric($page->edit) ? $sources->firstWhere('id', (int) $page->edit) : null;
        $presetKey = $page->draft['source']['preset_key'] ?? null;
        $preset = $this->presets->find(is_string($presetKey) ? $presetKey : null);
        $connector = $source?->connector;
        $set = $source === null ? null : TrafficPriceSet::current();

        return [
            'new' => $source === null,
            // What a confirmed account from the source is worth, its own price or the site's, set on Value.
            'accountPrice' => $set === null ? null : [
                'price' => $set->worth()->accountPriceOf((int) $source->id),
                'own' => $set->worth()->hasOwnAccountPrice((int) $source->id),
                'site' => (string) $set->account_price,
                // The same period, at the card where it is set.
                'url' => TrafficSourcesPage::getUrl(['tab' => 'value', ...self::periodQuery($page)]).'#traffic-value-sources',
            ],
            'preset' => $preset,
            'connector' => $connector === null ? null : [
                ...($connectorPreset = $this->presets->connector($connector->value) ?? []),
                'promise' => str_replace(':site', site_name(), (string) ($connectorPreset['promise'] ?? '')),
                'verified' => $this->connectors->verified($connector),
            ],
            'connection' => $source?->apiConnection?->only(['status', 'account_label', 'last_synced_at', 'next_sync_at', 'last_error', 'spend_closed_through']),
            'events' => array_map(fn (TrafficEvent $event): string => $event->value, TrafficEvent::postbackEvents()),
            'floors' => collect(TrafficEvent::settledFloors())->mapWithKeys(fn (TrafficEvent $event): array => [$event->value => self::ruleEvent($event->value)])->all(),
            'sharedGoals' => array_map(
                fn (array $keys): array => array_map(fn (int|string $key): string => self::ruleEvent((string) ($page->draft['rules'][$key]['event'] ?? '')), $keys),
                PostbackTemplate::sharedGoals($page->draft['rules']),
            ),
            'timezones' => ['UTC', 'America/New_York', 'Europe/London', 'Europe/Berlin', 'Asia/Singapore'],
            'thresholds' => ($source !== null ? $source->type : TrafficSourceType::tryFrom((string) ($page->draft['source']['type'] ?? ''))) === TrafficSourceType::Network ? $this->thresholds($page) : null,
            'blocking' => $source !== null && $source->type === TrafficSourceType::Network ? $this->blocking($source, $page->ready) : null,
        ];
    }

    /**
     * A network's own block rule thresholds in its editor: the general value
     * each one stands in for, shown in its empty field, and whether the draft
     * sets any of its own.
     *
     * @return array{general: array<string, string>, own: bool}
     */
    private function thresholds(TrafficSourcesPage $page): array
    {
        $general = BlockRule::effective(TrafficSetting::current()->only(BlockRule::KEYS));

        return [
            // A multiple as it reads (2, 1.5); the spend as money is written, to its cents.
            'general' => [
                ...array_map(fn (int|string $value): string => is_int($value) ? (string) $value : self::multiple($value), $general),
                'block_min_spend' => (string) $general['block_min_spend'],
            ],
            'own' => BlockRule::own($page->draft['source']) !== [],
        ];
    }

    /**
     * A saved network's Blocking pane, read as the source is saved: the block
     * rule's thresholds that apply to it — its own where it set them, which
     * it names — its opened rate over the rule's 30 days and the share that
     * calls a site bots against it, the target price of an account its
     * money waits for, and whether the worth is known yet; the source's
     * blocks in force — sites and zones, by an administrator or by the rule —
     * and where every block stands with the network; the rule's blocks today
     * against the source's cap and when the rule last ran; why the rule
     * can't block for it, if it can't; and the ways to its blocked sites and
     * zones in the report and to its blocks log. A bounded few reads, however
     * many blocks; its visits only once the page is in a browser.
     *
     * @return array<string, mixed>
     */
    private function blocking(TrafficSource $source, bool $ready): array
    {
        $counts = $this->blocks->counts((int) $source->id, CarbonImmutable::now());
        $own = $source->only(BlockRule::KEYS);
        $rule = BlockRule::effective(TrafficSetting::current()->only(BlockRule::KEYS), $own);
        $accounts = $this->blocks->worthAccounts();
        $connection = $source->apiConnection;
        $connector = $source->connector === null ? null : $this->connectors->connector($source->connector);
        $api = $source->connector === null ? 'The network’s API' : 'The '.($this->presets->connector($source->connector->value)['name'] ?? 'network’s API');
        $level = $source->block_level === TrafficBlockLevel::Zone ? 'zones' : 'sites';
        // The list by zone lists zone blocks and site blocks alike; by site, only site blocks.
        $listBy = match (true) {
            $counts['zones'] > 0 => 'zone',
            $counts['sites'] > 0 => 'site',
            default => $source->block_level->value,
        };
        // Its own average over the rule's 30 days, which the share calling a site bots may lean on.
        $month = TrafficReportFilter::lastDays(30, CarbonImmutable::now());
        $average = $ready
            ? collect($this->reports->level(new TrafficReportFilter($month->fromDay, $month->toDay, ['source_id' => (int) $source->id]), TrafficDimension::Source))
                ->reduce(fn (ReportFigures $sum, array $group): ReportFigures => $sum->plus($group['figures']), new ReportFigures)->openRate()
            : null;
        $target = BlockRule::target(null, $source->target_cpa);
        $needed = BlockJudge::spendNeeded($rule, $target['amount'] ?? null);

        return [
            'counts' => $counts,
            // The network as its own panel is named: "in the PopAds panel".
            'network' => $source->connector?->label() ?? 'network’s',
            'cap' => (int) $source->block_daily_cap,
            'auto' => (bool) $source->block_auto,
            'ranAt' => TrafficSetting::blockRuleRanAt(),
            'rule' => [
                'bot_clicks' => (int) $rule['block_bot_clicks'],
                'open_below' => (int) $rule['block_open_below'],
                'open_below_average' => (int) $rule['block_open_below_average'],
                'open_floor' => (int) $rule['block_open_floor'],
                'min_spend' => TrafficMoney::format($rule['block_min_spend']),
                'min_clicks' => (int) $rule['block_min_clicks'],
                'roi_below' => (int) $rule['block_roi_below'],
                'grace_days' => (int) $rule['block_grace_days'],
                'target_multiple' => self::multiple((string) $rule['block_target_multiple']),
            ],
            'own' => BlockRule::own($own),
            // Read only once the page is in a browser: until then the share stands as the rule sets it.
            'average' => $ready ? [
                'rate' => $average,
                'below' => BlockJudge::openBelow($rule, $average),
                'fromAverage' => BlockJudge::openBelowFromAverage($rule, $average),
                'fromFloor' => BlockJudge::openBelowFromFloor($rule, $average),
            ] : null,
            'target' => $target === null || $needed === null ? null : ['amount' => TrafficMoney::format($target['amount']), 'spend' => TrafficMoney::format($needed)],
            'campaignTargets' => TrafficCampaign::query()->where('source_id', $source->id)->where('target_cpa', '>', 0)->count(),
            'worth' => ['accounts' => $accounts, 'needed' => TrafficBlocksQuery::WORTH_KNOWN_AT, 'known' => $accounts >= TrafficBlocksQuery::WORTH_KNOWN_AT],
            'connected' => $connection?->status === TrafficConnectionStatus::Connected,
            'listsError' => $connection === null || $connection->status === TrafficConnectionStatus::Disconnected ? null : $this->blockListsError($connection),
            // What keeps the rule from blocking for this source by itself, in the order it asks.
            'why' => array_values(array_filter([
                $source->status !== TrafficSourceStatus::Active
                    ? ($source->status === TrafficSourceStatus::Ended ? 'The source has ended' : 'The source is paused').': the rule blocks only for an active source.'
                    : null,
                $source->connector === null ? site_name().' has no API for this network: block by hand and copy the IDs into its blacklist.' : null,
                $source->connector !== null && $connection?->status !== TrafficConnectionStatus::Connected
                    ? ($connection === null || $connection->status === TrafficConnectionStatus::Disconnected ? 'No API connected: connect it below.' : 'The API connection needs attention: reconnect it below.')
                    : null,
                $source->connector !== null && ! $this->connectors->verified($source->connector) ? $api.' isn’t verified on a real account yet, so the rule doesn’t block through it.' : null,
                $connector !== null && ! ($connector instanceof EditsBlockLists && in_array($source->block_level, $connector->blockLevels(), true))
                    ? $api.' can’t block '.$level.': block by '.($level === 'zones' ? 'site' : 'zone').', or by hand.'
                    : null,
            ])),
            // Blocked lists every block in force whatever the period, so the default period does.
            'sitesUrl' => TrafficSourcesPage::getUrl(array_filter([
                'view' => 'sites',
                'source' => $source->id,
                'list' => $listBy === 'site' ? 'site' : null,
                'seg' => 'blocked',
            ])).'#traffic-performance',
            'logUrl' => TrafficSourcesPage::getUrl(['tab' => 'logs', 'log' => 'blocks', 'source' => $source->id]),
        ];
    }

    /**
     * Why the last read of a source's campaigns' block lists failed, naming
     * the campaign whose list it couldn't read — by its name, else its ID
     * with the network — and when; nothing once a read went through, nor
     * while the API is disconnected, with nothing to read it again.
     */
    private function blockListsError(TrafficApiConnection $connection): ?string
    {
        if ($connection->block_lists_error === null) {
            return null;
        }

        $external = $connection->block_lists_error_campaign;
        $name = $external === null ? null : TrafficCampaign::query()->where('source_id', $connection->source_id)->where('external_id', $external)->value('name');
        $whose = $external === null ? 'the campaigns’ block lists' : ($name ?? 'Campaign '.$external).'’s block list';

        return 'Couldn’t read '.$whose.($connection->block_lists_read_at !== null ? ' at '.$connection->block_lists_read_at->format('j M H:i') : '').': '
            .(TrafficConnectorFailure::tryFrom($connection->block_lists_error)?->message() ?? 'The network answered in a way '.site_name().' doesn’t understand.');
    }

    /** A postback rule's event, as the editor names it. */
    private static function ruleEvent(string $event): string
    {
        return $event === TrafficEvent::Goal->value ? 'Flow goal reached' : (TrafficEvent::tryFrom($event)?->label() ?? $event);
    }

    /**
     * A campaign's panel: the campaign, its source and whether the network
     * reports its targeting, its figures over the last 30 days and by stage —
     * read only once the page is in a browser, null until then — its history,
     * and what its fields choose from.
     *
     * @return array<string, mixed>|null
     */
    private function campaignPanel(int $id, bool $ready): ?array
    {
        $campaign = TrafficCampaign::query()->find($id);

        if ($campaign === null) {
            return null;
        }

        $source = TrafficSource::query()->find($campaign->source_id);
        $month = TrafficReportFilter::lastDays(30, CarbonImmutable::now());

        return [
            'model' => $campaign,
            'source' => $source?->name,
            'sourceModel' => $source,
            'reported' => TrafficApiConnection::query()->where('source_id', $campaign->source_id)->where('status', TrafficConnectionStatus::Connected)->exists(),
            'figures' => $ready ? ($this->reports->byCampaign(new TrafficReportFilter($month->fromDay, $month->toDay, ['campaign_id' => (int) $campaign->id]))[$campaign->id] ?? new ReportFigures) : null,
            'stages' => $ready ? $this->reports->stages($campaign->id) : null,
            'history' => TrafficCampaignEvent::query()->where('campaign_id', $campaign->id)->orderByDesc('occurred_at')->orderByDesc('id')->limit(30)->get(),
            'games' => Game::query()->orderBy('title')->pluck('title', 'id')->all(),
            'countries' => Countries::all(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function presetCards(): array
    {
        return [
            ...collect($this->presets->all())->map(fn (array $preset, string $key): array => [
                'key' => $key,
                'name' => $preset['name'],
                'formats' => implode(' · ', $preset['formats']),
                'api' => $preset['connector'] !== null,
                'custom' => (bool) ($preset['custom'] ?? false),
                'chips' => [
                    ['Link', 'link', false],
                    [($preset['postback']['url_template'] ?? '') === '' ? 'Postbacks: paste the address' : 'Postbacks', 'arrow-up-right', false],
                    $preset['connector'] !== null ? ['Spend via API', 'refresh-cw', true] : (collect($preset['link_params'])->contains('kind', 'cost') ? ['Cost in each click', 'link', false] : ['Spend: your rate', 'smartphone', false]),
                    $preset['connector'] !== null ? ['Blocking via API', 'ban', true] : ['Blocking by hand', 'ban', false],
                ],
                'note' => match (true) {
                    (bool) ($preset['custom'] ?? false) => 'Any network without a preset. Link, postbacks and reports work the same: you type in its tokens and postback address and check them with Check a link.',
                    $preset['connector'] !== null => 'Read through the built-in connector: spend, campaigns and blocking.',
                    ($preset['network_api'] ?? '') !== '' => 'Its API offers '.$preset['network_api'].' · no built-in connector yet',
                    default => '',
                },
            ])->values()->all(),
            ['key' => 'placement', 'name' => 'Banner or link', 'formats' => 'A placement bought at a fixed price', 'api' => false, 'custom' => false, 'chips' => [['Link', 'link', false], ['Fixed price', 'calendar', false]], 'note' => 'A banner, a partner site, a channel post or a newsletter: a tracking link and a price agreed up front. No click ID, so no postbacks.'],
        ];
    }

    /** @return array<string, mixed> the IP database serving, its last update and the credit its licence asks for */
    private function geo(GeoEdition $chosen): array
    {
        return app(GeoAttribution::class)->read($chosen);
    }

    /** @return array<string, array<string, string>> names for the IDs a report groups by */
    private function labels($sources): array
    {
        return [
            'source_id' => self::named($sources->pluck('name', 'id')),
            'channel' => self::named(collect(TrafficChannel::cases())->mapWithKeys(fn (TrafficChannel $channel): array => [$channel->value => ucfirst($channel->value)])),
            'campaign_id' => self::named(TrafficCampaign::query()->limit(2000)->get(['id', 'name', 'external_id'])->mapWithKeys(fn (TrafficCampaign $campaign): array => [$campaign->id => $campaign->name ?? 'Campaign '.$campaign->external_id])),
            'game_id' => self::named(Game::query()->pluck('title', 'id')),
            'version_id' => self::named(GameVersion::query()->pluck('number', 'id')->map(fn ($number): string => 'V'.$number)),
            'country' => Countries::all(),
            'signup_method' => self::named(collect(AccountSignupMethod::cases())->mapWithKeys(fn (AccountSignupMethod $method): array => [$method->value => $method->label()])),
            'signup_place' => self::named(collect(AccountSignupPlace::cases())->mapWithKeys(fn (AccountSignupPlace $place): array => [$place->value => $place->label()])),
            'connection' => self::named(collect(TrafficConnectionStatus::cases())->mapWithKeys(fn (TrafficConnectionStatus $status): array => [$status->value => ucfirst(str_replace('_', ' ', $status->value))])),
        ];
    }

    /**
     * @param  iterable<mixed, mixed>  $pairs
     * @return array<string, string>
     */
    private static function named(iterable $pairs): array
    {
        $named = [];

        foreach ($pairs as $id => $name) {
            $named[(string) $id] = (string) $name;
        }

        return $named;
    }
}
