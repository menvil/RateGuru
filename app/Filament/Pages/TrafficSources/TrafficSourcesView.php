<?php

namespace App\Filament\Pages\TrafficSources;

use App\Enums\AccountSignupMethod;
use App\Enums\AccountSignupPlace;
use App\Enums\GeoEdition;
use App\Enums\TrafficBlockMethod;
use App\Enums\TrafficCampaignStatus;
use App\Enums\TrafficChannel;
use App\Enums\TrafficConnectionStatus;
use App\Enums\TrafficDimension;
use App\Enums\TrafficEvent;
use App\Enums\TrafficPostbackStatus;
use App\Enums\TrafficSourceType;
use App\Enums\TrafficSpendMode;
use App\Enums\TrafficSpendOrigin;
use App\Filament\Pages\TrafficSourcesPage;
use App\Models\Game;
use App\Models\GameVersion;
use App\Models\TrafficApiConnection;
use App\Models\TrafficBlock;
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
use App\Queries\Traffic\TrafficLogsQuery;
use App\Queries\Traffic\TrafficReportQuery;
use App\Queries\Traffic\TrafficSpendQuery;
use App\Queries\Traffic\TrafficWorthQuery;
use App\Queries\Users\UnconfirmedAccountsQuery;
use App\Services\Geo\GeoAttribution;
use App\Services\Traffic\Connectors\ConnectorRegistry;
use App\Services\Traffic\TrafficPresetCatalog;
use App\Support\Geo\Countries;
use App\Support\Traffic\Blocking\BlockJudge;
use App\Support\Traffic\Links\LinkInspector;
use App\Support\Traffic\Links\TrackingLinkBuilder;
use App\Support\Traffic\Money\TrafficMoney;
use App\Support\Traffic\Postbacks\PostbackSchedule;
use App\Support\Traffic\Postbacks\PostbackTemplate;
use App\Support\Traffic\Postbacks\PostbackUrl;
use App\Support\Traffic\Reports\ReportFigures;
use App\Support\Traffic\Reports\ReportPeriod;
use App\Support\Traffic\Reports\SignupFigures;
use App\Support\Traffic\Reports\SiteZoneList;
use App\Support\Traffic\Reports\TrafficReportFilter;
use App\Support\Traffic\Worth\Slice;
use App\Support\Traffic\Worth\StepLabels;
use App\Support\Traffic\Worth\StepWorth;
use App\Support\Traffic\Worth\WorthPrices;
use BackedEnum;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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

    /** @var array<string, array{rule: array<string, mixed>, startRates: array<int, float|null>, worthKnown: bool, blocks: Collection<string, TrafficBlock>}> what the block rule needs, by filter, for this render */
    private array $judged = [];

    public function __construct(
        private readonly TrafficReportQuery $reports,
        private readonly TrafficSpendQuery $spend,
        private readonly TrafficLogsQuery $logs,
        private readonly TrafficPresetCatalog $presets,
        private readonly ConnectorRegistry $connectors,
    ) {}

    /** @return array{source: array<string, mixed>, rules: list<array<string, mixed>>} */
    public static function draftOf(TrafficSource $source): array
    {
        $values = $source->only(['type', 'name', 'link_key', 'preset_key', 'status', 'attribution_days', 'click_id_ttl_hours', 'target_cpa', 'reporting_timezone', 'link_params', 'response_rules',
            'spend_mode', 'spend_estimate', 'cost_unit', 'rate_unit', 'rate_mobile', 'rate_desktop', 'fixed_amount', 'fixed_period', 'fixed_from', 'fixed_to',
            'block_level', 'block_auto', 'block_daily_cap', 'place_site', 'place_position', 'creatives']);

        return [
            'source' => array_map(fn (mixed $value): mixed => $value instanceof BackedEnum ? $value->value : $value, $values),
            'rules' => $source->postbackRules->map(fn ($rule): array => ['id' => $rule->id, 'event' => $rule->event->value, 'goal_code' => (string) $rule->goal_code, 'needs_event' => (string) $rule->needs_event?->value, 'url_template' => $rule->url_template, 'enabled' => (bool) $rule->enabled])->values()->all(),
        ];
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
                'href' => TrafficSourcesPage::getUrl($id === 'report' ? [] : ['tab' => $id]),
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
            'gameOptions' => [['value' => '', 'label' => 'All games', 'trigger' => 'All games'], ...Game::query()->orderBy('title')->get(['id', 'title'])->map(fn (Game $game): array => ['value' => (string) $game->id, 'label' => $game->title])->all()],
            'campaignOptions' => [['value' => '', 'label' => 'All campaigns', 'trigger' => 'All'], ...TrafficCampaign::query()->when(is_numeric($page->source), fn ($query) => $query->where('source_id', (int) $page->source))->orderByDesc('first_click_at')->limit(100)->get(['id', 'name', 'external_id'])->map(fn (TrafficCampaign $campaign): array => ['value' => (string) $campaign->id, 'label' => $campaign->name ?? 'Campaign '.$campaign->external_id])->all()],
            'periodDays' => ReportPeriod::span(CarbonImmutable::parse($filter->fromDay, 'UTC'), CarbonImmutable::parse($filter->toDay, 'UTC')),
            'capture' => (bool) $settings->capture_enabled,
            'postbacks' => (bool) $settings->postbacks_enabled,
            'geo' => $this->geo($settings->geo_edition ?? GeoEdition::GeoLite2City),
            'ready' => $page->ready,
            'labels' => $this->labels($sources),
            'presetCards' => $page->adding ? $this->presetCards() : null,
            'editor' => $page->edit !== '' ? $this->editor($page, $sources) : null,
            'campaign' => $page->camp !== '' ? $this->campaignPanel((int) $page->camp) : null,
        ];

        if (! $page->ready) {
            return $data;
        }

        return [...$data, ...match ($page->tab) {
            'campaigns' => $this->campaigns($page, $filter, $sources),
            'value' => $this->value($filter, $sources),
            'spend' => $this->spendTab($page, $filter, $sources),
            'sources' => $this->sourcesTab($sources),
            'logs' => $this->logsTab($page),
            default => $this->report($page, $filter, $sources),
        }];
    }

    /** @return array<string, mixed> */
    private function report(TrafficSourcesPage $page, TrafficReportFilter $filter, $sources): array
    {
        $cutoff = UnconfirmedAccountsQuery::cutoff();
        $summary = $this->reports->summary($filter, $cutoff);
        $sum = fn (iterable $rows): ReportFigures => collect($rows)->reduce(fn (ReportFigures $total, array $row): ReportFigures => $total->plus($row['figures']), new ReportFigures);
        // Spend no visit took counts in the totals, but only where nothing narrows the visits to a game, a device, a campaign or unpaid traffic.
        $unallocated = array_intersect_key($filter->where, array_flip(['game_id', 'device', 'campaign_id'])) === [] && ! (array_key_exists('source_id', $filter->where) && $filter->where['source_id'] === null) && ($filter->where['channel'] ?? 'network') === 'network'
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
            'tree' => $list === null ? $this->tree($page, $filter, $sources, $labels) : [],
            'list' => $list,
            'worthBlocking' => $list['counts']['block'] ?? $this->worthBlocking($filter, $sources),
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

                return [
                    'key' => md5((string) json_encode($keys)),
                    // A campaign, site or zone is the ad networks' own: unpaid traffic has none, and is one row.
                    'label' => $byRef && ($keys['source_id'] ?? null) === null ? 'Unpaid traffic' : $this->nodeLabel($dimension, $keys, $labels),
                    'sub' => match (true) {
                        $dimension === TrafficDimension::Source => ($keys['source_id'] ?? null) === null ? 'Unpaid' : TrafficChannel::tryFrom((string) ($keys['channel'] ?? ''))?->label(),
                        $byRef && ($keys['source_id'] ?? null) !== null => $source->name ?? 'Source #'.$keys['source_id'],
                        default => null,
                    },
                    'mono' => $dimension === TrafficDimension::Site || $dimension === TrafficDimension::Zone,
                    // A network's site or zone opens in Sites & zones, where it is blocked.
                    'find' => in_array($dimension, [TrafficDimension::Site, TrafficDimension::Zone], true) && $source?->type === TrafficSourceType::Network && $ref !== null && $ref !== ''
                        ? ['source' => (int) $source->id, 'level' => $dimension->value, 'ref' => (string) $ref]
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
                $block = $judge['blocks']->get($keys['source_id'].':'.$dimension->value.':'.$ref);
                $row['flag'] = $this->flag(BlockJudge::judge($group['figures'], $judge['startRates'][$keys['source_id']] ?? null, $judge['worthKnown'], $judge['rule'])['state'], $block);
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
     * What the block rule needs to judge a site or zone: its thresholds,
     * each source's start rate, whether worth is measured yet, and the blocks.
     *
     * @return array{rule: array<string, mixed>, startRates: array<int, float|null>, worthKnown: bool, blocks: Collection<string, TrafficBlock>}
     */
    private function judgeContext(TrafficReportFilter $filter): array
    {
        $id = md5(serialize($filter));

        if (isset($this->judged[$id])) {
            return $this->judged[$id];
        }

        $settings = TrafficSetting::current();
        $run = TrafficChanceRun::query()->where('status', 'finished')->orderByDesc('id')->first();
        $startRates = [];

        foreach ($this->reports->level(new TrafficReportFilter($filter->fromDay, $filter->toDay, array_diff_key($filter->where, array_flip(['site_ref', 'zone_ref', 'campaign_id']))), TrafficDimension::Source) as $group) {
            if ($group['keys']['source_id'] !== null) {
                $startRates[(int) $group['keys']['source_id']] = $group['figures']->startRate();
            }
        }

        return $this->judged[$id] = [
            'rule' => $settings->only(['block_min_spend', 'block_min_clicks', 'block_roi_below', 'block_open_below', 'block_bot_clicks', 'block_start_clicks', 'block_start_percent']),
            'startRates' => $startRates,
            'worthKnown' => $run !== null && (int) TrafficStepChance::query()->where('run_id', $run->id)->where('slice_key', '*')->where('step', 'opened')->value('slice_accounts') >= 30,
            'blocks' => TrafficBlock::query()->get()->keyBy(fn (TrafficBlock $block): string => $block->source_id.':'.$block->level->value.':'.$block->ref),
        ];
    }

    /** @return array{label: string, tone: string, tip: string}|null */
    private function flag(string $state, ?TrafficBlock $block): ?array
    {
        if ($block !== null) {
            $still = $block->clicks_after > 0;

            return ['label' => $still ? 'Blocked · still sending' : 'Blocked', 'tone' => $still ? 'danger' : 'neutral', 'tip' => 'Blocked '.$block->blocked_at->format('j M').match ($block->method) {
                TrafficBlockMethod::Api => ' through the network',
                TrafficBlockMethod::Auto => ' by the block rule',
                default => ' by hand',
            }.($still ? ' · '.$block->clicks_after.' clicks since' : '')];
        }

        return match ($state) {
            'bots' => ['label' => 'Likely bots', 'tone' => 'danger', 'tip' => 'Meets the block rule: few clicks open the page'],
            'losing' => ['label' => 'Losing', 'tone' => 'danger', 'tip' => 'Meets the block rule: enough data and ROI below the line'],
            'weak_start' => ['label' => 'Weak start', 'tone' => 'warning', 'tip' => 'Starts the game far less often than the source’s other places'],
            'profit' => ['label' => 'Profitable', 'tone' => 'success', 'tip' => 'Enough data, value above spend'],
            default => null,
        };
    }

    /**
     * Sites & zones: every network site or zone on the filters as one list,
     * each judged by the block rule and put in its segment, then searched,
     * thresholded, sorted and paged.
     *
     * @return array<string, mixed>
     */
    private function siteList(TrafficSourcesPage $page, TrafficReportFilter $filter, $sources): array
    {
        $rows = $this->siteRows($page, $filter, $sources);
        $list = SiteZoneList::of($rows, (string) $page->segment, (string) $page->q, (int) $page->minClicks, (string) $page->sort, (string) $page->dir, (int) $page->pageNumber);
        $all = collect($rows)->filter(fn (array $row): bool => $row['figures']->visits >= (int) $page->minClicks);
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
        $rule = TrafficSetting::current();

        return [
            ...$list,
            'noun' => $page->listBy === 'site' ? 'sites' : 'zones',
            'insight' => $all->isEmpty() ? null : $all->count().' '.($page->listBy === 'site' ? 'sites' : 'zones').'.'
                .($biggest > 0 ? ' The '.$biggest.' biggest take 80% of the spend: sort by Spend and start there.' : '')
                .($early->isNotEmpty() ? ' '.$early->count().' are too early to judge the money (under '.TrafficMoney::format($rule->block_min_spend).' and '.$rule->block_min_clicks.' clicks each) yet spent '.TrafficMoney::format($early->sum(fn (array $row): float => (float) $row['figures']->spend)).' together; bots show after '.$rule->block_bot_clicks.' clicks and a weak start after '.$rule->block_start_clicks.', so those are caught early.' : ''),
            'selectable' => collect($list['rows'])->filter(fn (array $row): bool => $page->segment === 'blocked' ? $row['blocked'] : ! $row['blocked'])->pluck('key')->values()->all(),
            'selected' => count($page->selected),
            'selectedBlocked' => collect($rows)->whereIn('key', $page->selected)->where('blocked', true)->count(),
            'copyIds' => collect($rows)->whereIn('key', $page->selected)->pluck('ref')->implode("\n"),
            'from' => count($list['matching']) === 0 ? 0 : ($list['page'] - 1) * SiteZoneList::PER_PAGE + 1,
            'to' => min(count($list['matching']), $list['page'] * SiteZoneList::PER_PAGE),
        ];
    }

    /**
     * The networks' sites or zones on the filters, judged: a zone where the
     * network names zones, else its site.
     *
     * @return list<array{key: string, ref: string, level: string, source: string, source_id: int, campaign: string|null, state: string, blocked: bool, flag: array<string, string>|null, figures: ReportFigures}>
     */
    private function siteRows(TrafficSourcesPage $page, TrafficReportFilter $filter, $sources): array
    {
        $byZone = $page->listBy !== 'site';
        $judge = $this->judgeContext($filter);
        $rows = $this->reports->zones($filter, $byZone);
        $campaigns = TrafficCampaign::query()->whereKey(collect($rows)->pluck('campaign_id')->filter()->unique()->all())->get(['id', 'name', 'external_id'])->keyBy('id');
        $out = [];

        foreach ($rows as $row) {
            $zone = $byZone && $row['zone_ref'] !== null && $row['zone_ref'] !== '';
            $ref = (string) ($zone ? $row['zone_ref'] : $row['site_ref']);

            if ($ref === '') {
                continue;
            }

            $level = $zone ? 'zone' : 'site';
            $block = $judge['blocks']->get($row['source_id'].':'.$level.':'.$ref);
            $state = BlockJudge::judge($row['figures'], $judge['startRates'][$row['source_id']] ?? null, $judge['worthKnown'], $judge['rule'])['state'];
            $campaign = $row['campaign_id'] !== null ? $campaigns->get($row['campaign_id']) : null;
            $out[] = [
                'key' => $row['source_id'].':'.$level.':'.$ref,
                'ref' => $ref,
                'level' => $level,
                'site' => $zone ? (string) $row['site_ref'] : null,
                'source' => (string) ($sources->firstWhere('id', $row['source_id'])->name ?? 'Source #'.$row['source_id']),
                'source_id' => $row['source_id'],
                'campaign' => $campaign !== null ? ($campaign->name ?? 'Campaign '.$campaign->external_id) : ($row['campaigns'] > 1 ? 'In '.$row['campaigns'].' campaigns' : null),
                'state' => $state,
                'blocked' => $block !== null,
                'flag' => $this->flag($state, $block),
                'figures' => $row['figures'],
            ];
        }

        return $out;
    }

    /** How many sites and zones the block rule picks out on the filters, not blocked yet. */
    private function worthBlocking(TrafficReportFilter $filter, $sources): int
    {
        $count = 0;
        $judge = $this->judgeContext($filter);

        foreach ($this->reports->zones($filter, true) as $row) {
            $zone = $row['zone_ref'] !== null && $row['zone_ref'] !== '';
            $ref = (string) ($zone ? $row['zone_ref'] : $row['site_ref']);
            $state = BlockJudge::judge($row['figures'], $judge['startRates'][$row['source_id']] ?? null, $judge['worthKnown'], $judge['rule'])['state'];

            if ($ref !== '' && in_array($state, ['bots', 'losing', 'weak_start'], true) && ! $judge['blocks']->has($row['source_id'].':'.($zone ? 'zone' : 'site').':'.$ref)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * What the page needs to tick sites and zones: the keys on the page on
     * screen and every key the list matches, each one that can be ticked.
     *
     * @return array{selectable: list<string>, matchingSelectable: list<string>}
     */
    public function siteSelection(TrafficSourcesPage $page, TrafficReportFilter $filter): array
    {
        $sources = TrafficSource::query()->get(['id', 'name']);
        $list = $this->siteList($page, $filter, $sources);
        $rows = collect($this->siteRows($page, $filter, $sources))->keyBy('key');

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
        $all = TrafficCampaign::query()
            ->when(is_numeric($page->source), fn ($query) => $query->where('source_id', (int) $page->source))
            ->orderByDesc('first_click_at')
            ->orderByDesc('id')
            ->limit(300)
            ->get();
        $stageOf = fn (TrafficCampaign $campaign): string => match (true) {
            $campaign->status === TrafficCampaignStatus::Archived => 'archived',
            $campaign->status === TrafficCampaignStatus::New => 'new',
            default => $campaign->stage->value ?? 'test',
        };
        $counts = ['all' => $all->where('status', '!=', TrafficCampaignStatus::Archived)->count()] + collect(['test', 'optimise', 'scale', 'paused', 'new', 'archived'])->mapWithKeys(fn (string $stage): array => [$stage => $all->filter(fn (TrafficCampaign $campaign): bool => $stageOf($campaign) === $stage)->count()])->all();
        $shown = $all->filter(fn (TrafficCampaign $campaign): bool => $page->stage === 'all' ? $campaign->status !== TrafficCampaignStatus::Archived : $stageOf($campaign) === $page->stage);
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
        $new = $all->first(fn (TrafficCampaign $campaign): bool => $campaign->status === TrafficCampaignStatus::New);

        return [
            'newCampaign' => $new === null ? null : [
                'id' => $new->id,
                'source' => $sources->firstWhere('id', $new->source_id)->name ?? 'A network',
                'external' => $new->external_id,
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
                    'name' => $campaign->status === TrafficCampaignStatus::New ? 'New campaign '.$campaign->external_id : ($campaign->name ?? 'Campaign '.$campaign->external_id),
                    'new' => $campaign->status === TrafficCampaignStatus::New,
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
     * the filters, where a site came through one campaign only.
     *
     * @return array<int, int> by campaign
     */
    private function toBlockByCampaign(TrafficReportFilter $filter): array
    {
        $judge = $this->judgeContext($filter);
        $counts = [];

        foreach ($this->reports->zones($filter, true) as $row) {
            if ($row['campaign_id'] === null) {
                continue;
            }

            $zone = $row['zone_ref'] !== null && $row['zone_ref'] !== '';
            $ref = (string) ($zone ? $row['zone_ref'] : $row['site_ref']);
            $state = BlockJudge::judge($row['figures'], $judge['startRates'][$row['source_id']] ?? null, $judge['worthKnown'], $judge['rule'])['state'];

            if ($ref !== '' && in_array($state, ['bots', 'losing', 'weak_start'], true) && ! $judge['blocks']->has($row['source_id'].':'.($zone ? 'zone' : 'site').':'.$ref)) {
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
     * day: its clicks, what their links said they cost, the network's own
     * figure and what reports count, with a day corrected or a file taken.
     *
     * @return array<string, mixed>
     */
    private function spendTab(TrafficSourcesPage $page, TrafficReportFilter $filter, $sources): array
    {
        $paid = $sources->whereIn('type', [TrafficSourceType::Network, TrafficSourceType::Placement])->values();
        $unallocated = collect($this->spend->unallocated($filter->fromDay, $filter->toDay));
        $bySource = collect($this->reports->level($filter, TrafficDimension::Source))->keyBy(fn (array $group): string => (string) ($group['keys']['source_id'] ?? ''));
        $network = TrafficSpendDay::query()->whereBetween('network_day', [$filter->fromDay, $filter->toDay])->whereIn('origin', [TrafficSpendOrigin::Api, TrafficSpendOrigin::Csv])->get(['source_id', 'campaign_id', 'origin', 'network_day', 'amount']);
        $campaignFigures = $this->reports->byCampaign($filter);
        $connections = TrafficApiConnection::query()->get()->keyBy('source_id');
        $users = fn (array $modes): array => $paid->filter(fn (TrafficSource $source): bool => in_array($source->spend_mode, $modes, true))->pluck('name')->all();
        $chosen = $paid->firstWhere('id', (int) $page->spendSource);
        $days = $chosen === null ? [] : $this->spend->days((int) $chosen->id, $filter->fromDay, $filter->toDay);
        $today = CarbonImmutable::now($chosen->reporting_timezone ?? 'UTC')->toDateString();

        return [
            'methods' => [
                ['icon' => 'refresh-cw', 'name' => 'Network API', 'text' => 'RateGuru asks the network for its spend per campaign, site, zone and day, every few hours. Exactly what the network bills. Today is estimated until the next sync.', 'users' => $users([TrafficSpendMode::Api])],
                ['icon' => 'link', 'name' => 'Price in each click', 'text' => 'The network puts the price of every click into the link. Exact per click, available at once, no typing.', 'users' => $users([TrafficSpendMode::ClickCost])],
                ['icon' => 'smartphone', 'name' => 'Rate you set', 'text' => 'You type the price you bid, per device. Clicks × rate. An estimate: good enough to compare zones, not to match the invoice.', 'users' => $users([TrafficSpendMode::Rate])],
                ['icon' => 'calendar', 'name' => 'Fixed price', 'text' => 'A set amount for a day, a month or once, spread evenly over the days and split across visits.', 'users' => $users([TrafficSpendMode::Fixed])],
                ['icon' => 'file', 'name' => 'Typed in or imported', 'text' => 'Type the spend per campaign and day, or import the network’s CSV export. It replaces any estimate for those days.', 'users' => $users([TrafficSpendMode::Manual])],
            ],
            'spendRows' => $paid->map(function (TrafficSource $source) use ($bySource, $unallocated, $network, $campaignFigures, $connections, $page): array {
                $figures = $bySource->get((string) $source->id)['figures'] ?? new ReportFigures;
                $without = $unallocated->where('source_id', $source->id)->sum(fn (array $row): float => (float) $row['amount']);
                $reported = TrafficMoney::of($figures->spend)->plus(TrafficMoney::of($without));
                $networkFacts = $network->where('source_id', $source->id);
                $networkSum = $networkFacts->sum(fn (TrafficSpendDay $fact): float => (float) $fact->amount);
                $connection = $connections->get($source->id);
                $campaigns = TrafficCampaign::query()->where('source_id', $source->id)->orderByDesc('first_click_at')->limit(50)->get(['id', 'external_id', 'name', 'game_id']);

                return [
                    'id' => $source->id,
                    'name' => $source->name,
                    'kind' => $source->type === TrafficSourceType::Placement ? 'Banner or link' : 'Ad network · '.$campaigns->count().' '.Str::plural('campaign', $campaigns->count()),
                    'how' => $this->spendHow($source),
                    'visits' => $figures->visits,
                    'reported' => (string) $reported,
                    'estimated' => $figures->spendEstimated,
                    'without' => (string) $without,
                    'network' => match (true) {
                        $source->spend_mode === TrafficSpendMode::Fixed => ['Agreed price', null],
                        $networkFacts->isEmpty() => ['Not checked', $source->spend_mode === TrafficSpendMode::Api ? 'Waiting for the first sync' : 'Import the network’s CSV to compare'],
                        default => [TrafficMoney::format($networkSum), $networkSum > 0 ? 'Differs by '.number_format(((float) (string) $reported - $networkSum) / $networkSum * 100, 1).'%' : null],
                    },
                    'status' => $this->spendStatus($source, $connection),
                    'api' => $connection !== null,
                    'open' => in_array((int) $source->id, $page->spendOpen, true),
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
                'network' => $row['figures']['api'] ?? $row['figures']['csv'] ?? null,
                'networkOpen' => $day === $today,
                'origin' => $row['counted_origin'],
                'counted' => $row['counted'],
                'allocated' => $row['allocated'],
            ])->values()->all(),
            'correctDays' => collect(range(0, 13))->mapWithKeys(fn (int $back): array => [CarbonImmutable::parse($today)->subDays($back)->toDateString() => $back === 0 ? 'Today' : ($back === 1 ? 'Yesterday' : CarbonImmutable::parse($today)->subDays($back)->format('d M'))])->all(),
        ];
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
            $ruleCount = $rules->where('source_id', $source->id)->count();
            $failing = $failed->where('source_id', $source->id);
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
                'internal' => ['Inside RateGuru', 'home', 'From the feed, a post page, another game or a notification on this site.'],
            ])->map(fn (array $channel, string $key): array => ['key' => $key, 'name' => $channel[0], 'icon' => $channel[1], 'how' => $channel[2], 'visits' => ($byGroup->get('c:'.$key)['figures'] ?? new ReportFigures)->visits])->values()->all(),
        ];
    }

    /**
     * Logs, as the reference draws them: postbacks — the milestone, how the
     * account came where it did, the click, the exact request and the
     * network's answer — milestones, clicks and blocking, newest first, fifty
     * to a page, with how many postbacks are failing.
     *
     * @return array<string, mixed>
     */
    private function logsTab(TrafficSourcesPage $page): array
    {
        $source = is_numeric($page->source) ? (int) $page->source : null;
        $logPage = match ($page->log) {
            'milestones' => $this->logs->milestones($source, $page->pageNumber),
            'clicks' => $this->logs->clicks($source, null, $page->pageNumber),
            'blocks' => $this->logs->blocks($source, $page->pageNumber),
            default => $this->logs->postbacks($source, $page->logFilter === 'failed' ? TrafficPostbackStatus::Failed->value : null, $page->pageNumber),
        };
        $failing = TrafficPostback::query()->where('status', TrafficPostbackStatus::Failed)->where('created_at', '>=', now()->subDays(7))->when($source !== null, fn ($query) => $query->where('source_id', $source))->count();

        return [
            'logPage' => $logPage,
            'logRows' => $page->log === 'postbacks' ? $this->postbackRows($logPage->items()) : [],
            'failing' => $failing,
            'tries' => count(PostbackSchedule::RETRY_AFTER) + 1,
        ];
    }

    /**
     * Each postback of a page as the log shows it.
     *
     * @param  list<TrafficPostback>  $postbacks
     * @return list<array<string, mixed>>
     */
    private function postbackRows(array $postbacks): array
    {
        $conversions = TrafficConversion::query()->whereKey(array_map(fn (TrafficPostback $postback): int => (int) $postback->conversion_id, $postbacks))->with('visit.detail')->get()->keyBy('id');
        $places = ['game' => 'in the game', 'post' => 'on a post', 'feed' => 'in the feed', 'other' => 'elsewhere on the site'];
        $tries = count(PostbackSchedule::RETRY_AFTER) + 1;

        return array_map(function (TrafficPostback $postback) use ($conversions, $places, $tries): array {
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
                'url' => TrafficSourcesPage::getUrl(array_filter(['tab' => 'value', 'period' => $page->period === '30d' ? null : $page->period, 'from' => $page->from, 'to' => $page->to])).'#traffic-value-sources',
            ],
            'preset' => $preset,
            'connector' => $connector === null ? null : [...($this->presets->connector($connector->value) ?? []), 'verified' => $this->connectors->verified($connector)],
            'connection' => $source?->apiConnection?->only(['status', 'account_label', 'last_synced_at', 'next_sync_at', 'last_error', 'spend_closed_through']),
            'events' => array_map(fn (TrafficEvent $event): string => $event->value, TrafficEvent::postbackEvents()),
            'floors' => collect(TrafficEvent::settledFloors())->mapWithKeys(fn (TrafficEvent $event): array => [$event->value => self::ruleEvent($event->value)])->all(),
            'sharedGoals' => array_map(
                fn (array $keys): array => array_map(fn (int|string $key): string => self::ruleEvent((string) ($page->draft['rules'][$key]['event'] ?? '')), $keys),
                PostbackTemplate::sharedGoals($page->draft['rules']),
            ),
            'timezones' => ['UTC', 'America/New_York', 'Europe/London', 'Europe/Berlin', 'Asia/Singapore'],
        ];
    }

    /** A postback rule's event, as the editor names it. */
    private static function ruleEvent(string $event): string
    {
        return $event === TrafficEvent::Goal->value ? 'Flow goal reached' : (TrafficEvent::tryFrom($event)?->label() ?? $event);
    }

    /**
     * A campaign's panel: the campaign, its source and whether the network
     * reports its targeting, its figures over the last 30 days and by stage,
     * its history, and what its fields choose from.
     *
     * @return array<string, mixed>|null
     */
    private function campaignPanel(int $id): ?array
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
            'figures' => $this->reports->byCampaign($month)[$campaign->id] ?? new ReportFigures,
            'stages' => $this->reports->stages($campaign->id),
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
                    $preset['connector'] !== null => 'Read through the RateGuru connector: spend, campaigns and blocking.',
                    ($preset['network_api'] ?? '') !== '' => 'Its API offers '.$preset['network_api'].' · no RateGuru connector yet',
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
