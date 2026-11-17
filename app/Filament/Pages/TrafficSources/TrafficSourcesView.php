<?php

namespace App\Filament\Pages\TrafficSources;

use App\Enums\GeoEdition;
use App\Enums\TrafficBlockMethod;
use App\Enums\TrafficCampaignStatus;
use App\Enums\TrafficChannel;
use App\Enums\TrafficConnectionStatus;
use App\Enums\TrafficDimension;
use App\Enums\TrafficEvent;
use App\Enums\TrafficPostbackStatus;
use App\Enums\TrafficSourceType;
use App\Filament\Pages\TrafficSourcesPage;
use App\Models\Game;
use App\Models\GameVersion;
use App\Models\TrafficBlock;
use App\Models\TrafficCampaign;
use App\Models\TrafficCampaignEvent;
use App\Models\TrafficChanceRun;
use App\Models\TrafficPostback;
use App\Models\TrafficSetting;
use App\Models\TrafficSource;
use App\Models\TrafficStepChance;
use App\Queries\Traffic\TrafficLogsQuery;
use App\Queries\Traffic\TrafficReportQuery;
use App\Queries\Traffic\TrafficSpendQuery;
use App\Services\Traffic\Connectors\ConnectorRegistry;
use App\Services\Traffic\TrafficPresetCatalog;
use App\Support\Geo\Countries;
use App\Support\Geo\GeoDatabaseLocator;
use App\Support\Geo\Update\GeoStateStore;
use App\Support\Traffic\Blocking\BlockJudge;
use App\Support\Traffic\Links\LinkInspector;
use App\Support\Traffic\Links\TrackingLinkBuilder;
use App\Support\Traffic\Money\TrafficMoney;
use App\Support\Traffic\Reports\ReportFigures;
use App\Support\Traffic\Reports\SiteZoneList;
use App\Support\Traffic\Reports\TrafficReportFilter;
use App\Support\Traffic\Worth\TrafficStep;
use BackedEnum;
use Illuminate\Support\Collection;

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
            'rules' => $source->postbackRules->map(fn ($rule): array => ['id' => $rule->id, 'event' => $rule->event->value, 'goal_code' => (string) $rule->goal_code, 'url_template' => $rule->url_template, 'enabled' => (bool) $rule->enabled])->values()->all(),
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
            'periodOptions' => collect(TrafficSourcesPage::PERIODS)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all(),
            'periodLabel' => TrafficSourcesPage::PERIODS[$page->period].' · '.$filter->fromDay.($filter->fromDay === $filter->toDay ? '' : ' – '.$filter->toDay),
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
        $summary = $this->reports->summary($filter);
        $sum = fn (iterable $rows): ReportFigures => collect($rows)->reduce(fn (ReportFigures $total, array $row): ReportFigures => $total->plus($row['figures']), new ReportFigures);
        // Spend no visit took counts in the totals, but only where nothing narrows the visits to a game, a device, a campaign or unpaid traffic.
        $unallocated = array_intersect_key($filter->where, array_flip(['game_id', 'device', 'campaign_id'])) === [] && ! (array_key_exists('source_id', $filter->where) && $filter->where['source_id'] === null) && ($filter->where['channel'] ?? 'network') === 'network'
            ? $this->spend->unallocated($filter->fromDay, $filter->toDay, isset($filter->where['source_id']) ? (int) $filter->where['source_id'] : null)
            : [];
        $extra = array_reduce($unallocated, fn (string $total, array $row): string => TrafficMoney::stored(TrafficMoney::of($total)->plus(TrafficMoney::of($row['amount']))), '0');
        $total = $sum($summary)->withSpend($extra);
        $paid = $sum(array_filter($summary, fn (array $row): bool => in_array($row['channel'], ['network', 'placement'], true)))->withSpend($extra);
        $byDevice = [];
        $path = array_fill_keys(['signup_form', 'signup', 'login', 'late', 'place_game', 'place_post', 'place_feed', 'place_other', 'within_10_minutes', 'within_an_hour', 'within_a_day'], 0);
        $paidNew = 0;

        foreach ($summary as $row) {
            if (in_array($row['channel'], ['network', 'placement'], true)) {
                $byDevice[$row['device']] = isset($byDevice[$row['device']]) ? $byDevice[$row['device']]->plus($row['figures']) : $row['figures'];
                $paidNew += $row['figures']->newVisitors();

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
            'views' => collect(self::VIEWS)->map(fn (array $view): string => $view[0])->all(),
            'chain' => self::VIEWS[$page->reportView][1] === null ? [] : array_map(fn (string $dimension): string => TrafficDimension::from($dimension)->label(), self::VIEWS[$page->reportView][1]),
            'tree' => $list === null ? $this->tree($page, $filter, $sources, $labels) : [],
            'list' => $list,
            'worthBlocking' => $list['counts']['block'] ?? $this->worthBlocking($filter, $sources),
            'unallocated' => $extra,
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

    /** @return array<string, mixed> */
    private function campaigns(TrafficSourcesPage $page, TrafficReportFilter $filter, $sources): array
    {
        $figures = $this->reports->byCampaign($filter);
        $campaigns = TrafficCampaign::query()
            ->when(is_numeric($page->source), fn ($query) => $query->where('source_id', (int) $page->source))
            ->orderByDesc('first_click_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            // New ones first — they came in by themselves and want setting up — then the running ones, then the archive.
            ->sortBy(fn (TrafficCampaign $campaign): int => match ($campaign->status) {
                TrafficCampaignStatus::New => 0,
                TrafficCampaignStatus::Active => 1,
                default => 2,
            })
            ->values();
        $source = is_numeric($page->source) ? $sources->firstWhere('id', (int) $page->source) : null;
        $games = Game::query()->orderBy('title')->get(['id', 'slug', 'title']);
        $game = $games->firstWhere('id', (int) $page->linkGame) ?? $games->first();

        return [
            'campaigns' => $campaigns->map(fn (TrafficCampaign $campaign): array => [
                'id' => $campaign->id,
                'name' => $campaign->name ?? 'Campaign '.$campaign->external_id,
                'external' => $campaign->external_id,
                'source' => $sources->firstWhere('id', $campaign->source_id)?->name,
                'status' => $campaign->status->value,
                'stage' => $campaign->stage?->value,
                'figures' => $figures[$campaign->id] ?? new ReportFigures,
            ])->all(),
            'link' => $source === null || $game === null ? null : ($source->type === TrafficSourceType::Network
                ? TrackingLinkBuilder::forNetwork(route('games.play', ['slug' => $game->slug]), $source->link_key, $source->link_params)
                : TrackingLinkBuilder::forPlacement(route('games.play', ['slug' => $game->slug]), $source->link_key, $source->creatives[0] ?? null)),
            'games' => $games->mapWithKeys(fn (Game $game): array => [(string) $game->id => $game->title])->all(),
            'inspection' => trim($page->checkLink) === '' ? null : LinkInspector::inspect(
                $page->checkLink,
                (string) parse_url((string) config('app.url'), PHP_URL_HOST),
                fn (string $key): ?array => ($found = $sources->firstWhere('link_key', $key)) === null ? null : ['id' => $found->id, 'name' => $found->name, 'link_params' => $found->link_params, 'api_spend' => $found->spend_mode->value === 'api'],
                fn (string $slug): ?string => $games->firstWhere('slug', $slug)?->title,
                fn (int $sourceId, string $id): ?array => ($found = TrafficCampaign::query()->where('source_id', $sourceId)->where('external_id', $id)->first()) === null ? null : ['name' => $found->name, 'archived' => $found->status === TrafficCampaignStatus::Archived],
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function value(TrafficReportFilter $filter, $sources): array
    {
        $run = TrafficChanceRun::query()->where('status', 'finished')->orderByDesc('id')->first();
        $site = $run === null ? collect() : TrafficStepChance::query()->where('run_id', $run->id)->where('slice_key', '*')->get()->keyBy('step');

        return [
            'run' => $run === null ? null : ['at' => $run->finished_at, 'visits' => $run->visits_scanned, 'accounts' => (int) ($site['opened']->accounts ?? 0)],
            'chances' => $this->orderedChances($site),
            'bySource' => array_map(fn (array $group): array => ['keys' => $group['keys'], 'figures' => $group['figures']], $this->reports->level($filter, TrafficDimension::Source)),
            'countries' => Countries::all(),
        ];
    }

    /**
     * The whole site's chances in the order of a play — opened, started, the
     * rounds, completed, what follows, signing up — then how often accounts
     * post and come back, named for what they are.
     *
     * @return list<array{step: string, label: string, visitors: int, accounts: int, chance: float}>
     */
    private function orderedChances($site): array
    {
        $rounds = $site->keys()->filter(fn ($step): bool => TrafficStep::isRound((string) $step))->map(fn ($step): int => (int) substr((string) $step, 6))->max() ?? 0;
        $order = [...array_values(array_diff(TrafficStep::all($rounds + 1), ['account', 'login'])), 'post_bonus', 'returned_bonus'];
        $labels = ['post_bonus' => 'Accounts that posted within 30 days', 'returned_bonus' => 'Accounts that came back on another day'];

        return collect($order)
            ->filter(fn (string $step): bool => $site->has($step))
            ->map(fn (string $step): array => [
                'step' => $step,
                'label' => $labels[$step] ?? ucfirst(str_replace('_', ' ', $step)),
                'visitors' => (int) $site[$step]->visitors,
                'accounts' => (int) $site[$step]->accounts,
                'chance' => (float) $site[$step]->chance,
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function spendTab(TrafficSourcesPage $page, TrafficReportFilter $filter, $sources): array
    {
        $unallocated = $this->spend->unallocated($filter->fromDay, $filter->toDay);

        return [
            'bySource' => array_map(fn (array $group): array => [
                'keys' => $group['keys'],
                'figures' => $group['figures'],
                'unallocated' => collect($unallocated)->where('source_id', (int) $group['keys']['source_id'])->reduce(fn (string $sum, array $row): string => TrafficMoney::stored(TrafficMoney::of($sum)->plus(TrafficMoney::of($row['amount']))), '0'),
            ], array_values(array_filter($this->reports->level($filter, TrafficDimension::Source), fn (array $group): bool => ($group['keys']['source_id'] ?? null) !== null))),
            'days' => is_numeric($page->source) ? $this->spend->days((int) $page->source, $filter->fromDay, $filter->toDay) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function sourcesTab($sources): array
    {
        $sources->load('apiConnection');
        $last = TrafficSource::query()->withMax('visits', 'arrived_at')->get()->pluck('visits_max_arrived_at', 'id');

        return [
            'sources' => $sources->map(fn (TrafficSource $source): array => [
                'id' => $source->id,
                'name' => $source->name,
                'type' => $source->type->value,
                'key' => $source->link_key,
                'status' => $source->status->value,
                'preset' => $this->presets->find($source->preset_key)['name'] ?? ($source->type === TrafficSourceType::Placement ? 'Banner or link' : 'No preset'),
                'connection' => $source->apiConnection?->status->value,
                'lastClick' => $last[$source->id] ?? null,
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function logsTab(TrafficSourcesPage $page): array
    {
        $source = is_numeric($page->source) ? (int) $page->source : null;

        return ['logPage' => match ($page->log) {
            'milestones' => $this->logs->milestones($source, $page->pageNumber),
            'postbacks' => $this->logs->postbacks($source, null, $page->pageNumber),
            'blocks' => $this->logs->blocks($source, $page->pageNumber),
            default => $this->logs->clicks($source, null, $page->pageNumber),
        }];
    }

    /** @return array<string, mixed> */
    private function editor(TrafficSourcesPage $page, $sources): array
    {
        $source = is_numeric($page->edit) ? $sources->firstWhere('id', (int) $page->edit) : null;
        $presetKey = $page->draft['source']['preset_key'] ?? null;
        $preset = $this->presets->find(is_string($presetKey) ? $presetKey : null);
        $connector = $source?->connector;

        return [
            'new' => $source === null,
            'preset' => $preset,
            'connector' => $connector === null ? null : [...($this->presets->connector($connector->value) ?? []), 'verified' => $this->connectors->verified($connector)],
            'connection' => $source?->apiConnection?->only(['status', 'account_label', 'last_synced_at', 'next_sync_at', 'last_error', 'spend_closed_through']),
            'events' => array_map(fn (TrafficEvent $event): string => $event->value, TrafficEvent::postbackEvents()),
            'timezones' => ['UTC', 'America/New_York', 'Europe/London', 'Europe/Berlin', 'Asia/Singapore'],
        ];
    }

    /** @return array<string, mixed>|null */
    private function campaignPanel(int $id): ?array
    {
        $campaign = TrafficCampaign::query()->find($id);

        return $campaign === null ? null : [
            'model' => $campaign,
            'source' => TrafficSource::query()->whereKey($campaign->source_id)->value('name'),
            'stages' => $this->reports->stages($campaign->id),
            'history' => TrafficCampaignEvent::query()->where('campaign_id', $campaign->id)->orderByDesc('occurred_at')->orderByDesc('id')->limit(30)->get(),
            'games' => Game::query()->orderBy('title')->pluck('title', 'id')->all(),
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
            ])->values()->all(),
            ['key' => 'placement', 'name' => 'Banner or link', 'formats' => 'A placement bought at a fixed price', 'api' => false],
        ];
    }

    /** @return array<string, mixed> */
    private function geo(GeoEdition $chosen): array
    {
        $locator = app(GeoDatabaseLocator::class);
        $state = app(GeoStateStore::class)->read($chosen);
        $serving = $locator->serving($chosen);

        return [
            'chosen' => $chosen,
            'serving' => $serving,
            'installed' => $state['installed'] ?? null,
            'checked' => $state['last_checked_at'] ?? null,
            'error' => $state['last_error']['code'] ?? null,
        ];
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
