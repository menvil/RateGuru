<?php

namespace App\Filament\Pages\TrafficSources;

use App\Actions\Traffic\BlockTrafficRefsAction;
use App\Enums\GeoEdition;
use App\Enums\TrafficBlockLevel;
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
use App\Support\Traffic\Reports\ReportTree;
use App\Support\Traffic\Reports\TrafficReportFilter;
use BackedEnum;

/**
 * What each tab of Growth → Traffic sources shows, read for the tab on
 * screen only and only once the page is in a browser: names for the IDs the
 * figures are grouped by, the tree's level with its ten biggest groups,
 * sites and zones with the block rule's verdict, campaigns, spend by day,
 * the logs. Reads only; every change is the page's, through an Action.
 */
final class TrafficSourcesView
{
    /** The order a tree opens in: each dimension's next. */
    private const array NEXT = ['type' => 'source', 'source' => 'campaign', 'campaign' => 'site', 'site' => 'zone', 'zone' => 'device', 'device' => 'country', 'country' => 'city', 'city' => 'game', 'game' => 'version', 'version' => 'device'];

    public function __construct(
        private readonly TrafficReportQuery $reports,
        private readonly TrafficSpendQuery $spend,
        private readonly TrafficLogsQuery $logs,
        private readonly TrafficPresetCatalog $presets,
        private readonly ConnectorRegistry $connectors,
        private readonly BlockTrafficRefsAction $blocking,
    ) {}

    public static function columnOf(string $dimension): ?string
    {
        return TrafficDimension::tryFrom($dimension)?->columns()[0];
    }

    public static function nextDimension(string $dimension): string
    {
        return self::NEXT[$dimension] ?? 'source';
    }

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
        $total = array_reduce($summary, fn (ReportFigures $sum, array $row): ReportFigures => $sum->plus($row['figures']), new ReportFigures);
        $unallocated = $this->spend->unallocated($filter->fromDay, $filter->toDay, isset($filter->where['source_id']) ? (int) $filter->where['source_id'] : null);
        $extra = array_reduce($unallocated, fn (string $sum, array $row): string => TrafficMoney::stored(TrafficMoney::of($sum)->plus(TrafficMoney::of($row['amount']))), '0');
        $total = $total->withSpend($extra);
        $byDevice = [];
        $path = array_fill_keys(['signup_form', 'signup', 'login', 'late', 'place_game', 'place_post', 'place_feed', 'place_other', 'within_10_minutes', 'within_an_hour', 'within_a_day'], 0);

        foreach ($summary as $row) {
            $byDevice[$row['device']] = isset($byDevice[$row['device']]) ? $byDevice[$row['device']]->plus($row['figures']) : $row['figures'];

            foreach ($row['path'] as $key => $count) {
                $path[$key] += $count;
            }
        }

        $dimension = TrafficDimension::from((string) $page->by);
        $groups = array_map(fn (array $group): array => ['keys' => $group['keys'], 'figures' => $dimension === TrafficDimension::Source && isset($group['keys']['source_id'])
            ? $group['figures']->withSpend(collect($unallocated)->where('source_id', (int) $group['keys']['source_id'])->reduce(fn (string $sum, array $row): string => TrafficMoney::stored(TrafficMoney::of($sum)->plus(TrafficMoney::of($row['amount']))), '0'))
            : $group['figures']], $this->reports->level($filter, $dimension));

        $zones = null;

        if (in_array($dimension, [TrafficDimension::Site, TrafficDimension::Zone], true)) {
            $zones = $this->zones($filter, $dimension === TrafficDimension::Zone, $sources);
        }

        return [
            'total' => $total,
            'byDevice' => $byDevice,
            'accountsPath' => $path,
            'dimension' => $dimension,
            'level' => ReportTree::level($groups),
            'dimensions' => collect(TrafficDimension::cases())->map(fn (TrafficDimension $d): array => ['value' => $d->value, 'label' => $d->label()])->values()->all(),
            'pathSteps' => array_values(array_filter(explode(';', (string) $page->path))),
            'zones' => $zones,
            'unallocated' => $extra,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function zones(TrafficReportFilter $filter, bool $byZone, $sources): array
    {
        $settings = TrafficSetting::current();
        $rule = $settings->only(['block_min_spend', 'block_min_clicks', 'block_roi_below', 'block_open_below', 'block_bot_clicks', 'block_start_clicks', 'block_start_percent']);
        $run = TrafficChanceRun::query()->where('status', 'finished')->orderByDesc('id')->first();
        $worthKnown = $run !== null && (int) TrafficStepChance::query()->where('run_id', $run->id)->where('slice_key', '*')->where('step', 'opened')->value('accounts') >= 30;
        $rows = $this->reports->zones($filter, $byZone);
        $startRates = [];

        foreach (collect($rows)->groupBy('source_id') as $sourceId => $group) {
            $sum = $group->reduce(fn (ReportFigures $sum, array $row): ReportFigures => $sum->plus($row['figures']), new ReportFigures);
            $startRates[$sourceId] = $sum->startRate();
        }

        $level = $byZone ? TrafficBlockLevel::Zone : TrafficBlockLevel::Site;
        $blocks = TrafficBlock::query()->where('level', $level)->get()->keyBy(fn (TrafficBlock $block): string => $block->source_id.':'.$block->ref);

        return collect($rows)
            ->map(function (array $row) use ($byZone, $level, $blocks, $startRates, $worthKnown, $rule, $sources): array {
                $ref = (string) ($byZone ? $row['zone_ref'] : $row['site_ref']);
                $source = $sources->firstWhere('id', $row['source_id']);

                return [
                    ...$row,
                    'ref' => $ref,
                    'level' => $level->value,
                    'source' => $source->name ?? 'Source #'.$row['source_id'],
                    'judgement' => BlockJudge::judge($row['figures'], $startRates[$row['source_id']] ?? null, $worthKnown, $rule),
                    'block' => $blocks->get($row['source_id'].':'.$ref),
                    'api' => $source !== null && $this->blocking->canBlockViaApi($source, $level),
                ];
            })
            ->sortByDesc(fn (array $row): float => (float) $row['figures']->spend)
            ->take(200)
            ->values()
            ->all();
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
            'chances' => $site->map(fn ($row): array => ['step' => $row->step, 'visitors' => $row->visitors, 'accounts' => $row->accounts, 'chance' => (float) $row->chance])->values()->all(),
            'bySource' => array_map(fn (array $group): array => ['keys' => $group['keys'], 'figures' => $group['figures']], $this->reports->level($filter, TrafficDimension::Source)),
            'countries' => Countries::all(),
        ];
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
