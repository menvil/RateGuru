<?php

namespace App\Filament\Pages\Analytics;

use App\Actions\Analytics\SaveTrafficSegmentAction;
use App\Enums\AnalyticsDimension;
use App\Enums\GameStatus;
use App\Enums\SegmentVerdict;
use App\Enums\TrafficConnectionStatus;
use App\Enums\TrafficSourceStatus;
use App\Enums\TrafficSourceType;
use App\Enums\TrafficSpendMode;
use App\Filament\Pages\GrowthAnalyticsPage;
use App\Filament\Pages\TrafficSourcesPage;
use App\Models\Game;
use App\Models\TrackingLink;
use App\Models\TrafficCampaign;
use App\Models\TrafficSource;
use App\Queries\Analytics\AnalyticsVisitsQuery;
use App\Queries\Analytics\TrackingLinksQuery;
use App\Queries\Analytics\TrafficSegmentsQuery;
use App\Queries\Traffic\TrafficSpendQuery;
use App\Support\Analytics\ActivityDay;
use App\Support\Analytics\AnalyticsFilter;
use App\Support\Analytics\AnalyticsSource;
use App\Support\Analytics\CampaignKey;
use App\Support\Analytics\SegmentRules;
use App\Support\Analytics\VisitorFigures;
use App\Support\Traffic\Money\TrafficMoney;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Analytics → Campaigns, as the reference draws it: where the spend comes
 * from, the paid sources, and how each campaign did from visit to activated
 * user.
 *
 * Where spend comes from says, for reading only, how each paid source — or a
 * campaign of its own — counts what it spent, and whether the networks'
 * APIs report it; spend is never guessed from tags. Paid sources lists each
 * source set up under Traffic sources with the visitors it brought in the
 * period on the device chosen and what it spent there, leading to its
 * settings; a click on one filters the page by it, as Overview's sources do,
 * and its Segments does too. While a paid source is chosen, its segments
 * follow: the administrator's own slices of its clicks, by the parameters
 * they carry (TrafficSegmentsQuery), each against the whole source.
 *
 * Campaign performance is a tree: the campaigns the most people came
 * through — a paid source's campaign by its name, one named only by its
 * utm_campaign tag by the tag — then, under an open one, its creatives (or
 * "(no creative)"), and under an open creative the games its visitors
 * played, each leading to the game's own analytics on the Games tab, of the
 * same days and device. Each row has what its paid clicks cost — every
 * click, its page run or not, a game's row the clicks that landed on it, a
 * campaign's the spend none of its visits took too — whether any of it was
 * estimated, then its visitors, game starts, completions, accounts and
 * activated users, account and activation rates of new visitors, "—" below
 * the denominator a row needs, activation "Too early" while none of a row's
 * accounts has had its days to activate; and what each visitor, account and
 * activated user cost. A campaign without spend is Unpaid. The creative of a
 * campaign with the best activation rate of those with enough visitors is
 * marked. The campaign the most people came through and its biggest
 * creative open to begin with — the creative the address names instead,
 * listed even past the first ones and marked with its campaign — and any
 * row can be opened or closed. Every
 * filter of the page applies to the tree; the paid sources read the period
 * and device. Nobody matching the filters is said once, with nothing else
 * drawn.
 *
 * Opened from a tracking link — its campaign chosen, its creative named —
 * the tab says so in a banner above everything, with the way back to the
 * links, and marks the campaign's row as well as the creative's; a link no
 * visit came through yet says that, and whether its campaign already came
 * by other links. Reads only.
 */
final class CampaignsTab implements AnalyticsTab
{
    /** How many campaigns the tree lists, the most people first; every other campaign is counted together after them. */
    public const int CAMPAIGNS = 25;

    /** How many creatives an open campaign lists, the most people first — the one the address names taking the last place when it is past them; the rest of the campaign is counted together after them. */
    public const int CREATIVES = 25;

    /** How many games an open creative lists, the most people first. */
    public const int GAMES = 25;

    /** How many rows are kept opened or closed against how the tree opens to begin with: each open row reads its own. */
    public const int MOST_TOGGLED = 8;

    /** @var array<string, string> how the campaigns and their creatives can be sorted; by cost only while the campaigns spent anything */
    public const array SORTS = ['visitors' => 'Visitors', 'registration' => 'Registration rate', 'activation' => 'Activation rate', 'cost' => 'Cost / registration'];

    /** @var array<string, string> what each way of counting spend means, by TrafficSpendMode */
    private const array MODE_NOTES = [
        'api' => 'Read from the network’s API; each click is estimated until the day’s figures arrive.',
        'click_cost' => 'The price the network puts in each click’s link.',
        'rate' => 'Your price per click or per thousand, by device.',
        'price' => 'A campaign’s own price on the day of the click.',
        'fixed' => 'A price for a span of days, shared over the visits of those days.',
        'manual' => 'Figures typed in or imported; each click is estimated until they are.',
        'none' => 'Costs nothing.',
    ];

    /** How many sources a line of Where spend comes from names before saying how many more. */
    private const int NAMED_SOURCES = 3;

    public function __construct(
        private readonly AnalyticsVisitsQuery $visits,
        private readonly AnalyticsView $view,
        private readonly TrafficSpendQuery $spend,
        private readonly TrafficSegmentsQuery $segments,
        private readonly TrackingLinksQuery $links,
    ) {}

    /**
     * A creative as the address and a row name it: its key, or "(no
     * creative)" for a campaign's visits that came by none; empty for
     * anything a visit couldn't carry.
     */
    public static function readCreative(mixed $value): string
    {
        // An address reads a creative of digits alone — a network's creative id — as a number.
        $value = is_int($value) ? (string) $value : $value;

        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > CampaignKey::MAX_LENGTH || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            return '';
        }

        return $value;
    }

    /**
     * A row of the tree as the rows opened or closed name it: a campaign by
     * its key, a creative by its campaign's and its own as a row names it.
     */
    public static function node(string $campaign, ?string $creative = null): string
    {
        return (string) json_encode($creative === null ? [$campaign] : [$campaign, $creative], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, mixed> */
    public function data(GrowthAnalyticsPage $page, AnalyticsFilter $filter): array
    {
        $period = $filter->period;
        $has = $this->visits->has($filter);
        $link = $this->linkBanner($page, $filter, $has);

        if (! $has) {
            return ['empty' => true, 'link' => $link, 'range' => $period->range(), 'filtered' => $filter->device !== null || $filter->narrowsSource()];
        }

        $sources = TrafficSource::query()->with('apiConnection:id,source_id,status')->withCount(['campaigns', 'segments'])->orderBy('name')->orderBy('id')
            ->get(['id', 'name', 'type', 'status', 'spend_mode', 'connector']);
        $minimum = (int) config('analytics.row_min_denominator');

        return [
            'empty' => false,
            'link' => $link,
            'spendOrigin' => $this->spendOrigin($sources),
            'paidSources' => $this->paidSources($sources, $filter),
            'segments' => $this->segmentsOf($page, $filter, $sources),
            ...$this->tree($page, $filter, $minimum, $link !== null && $link['visits']),
        ];
    }

    /**
     * What the banner of the tracking link the tab was opened from says —
     * the link by its number and tags, and that its row is marked; or, while
     * no visit came through it yet, that it will appear after its first, or
     * that the campaign below already came by the same utm_campaign — and
     * whether any visit came through it. None while no link is named, the
     * one named is gone, or the campaign chosen isn't the link's.
     *
     * @param  bool  $has  whether anyone matches the filters
     * @return array{text: string, visits: bool}|null
     */
    private function linkBanner(GrowthAnalyticsPage $page, AnalyticsFilter $filter, bool $has): ?array
    {
        $link = $page->link === '' ? null : TrackingLink::query()->find((int) $page->link);

        if (! $link instanceof TrackingLink || $filter->campaignKey !== CampaignKey::tag($link->utm_campaign)) {
            return null;
        }

        $named = $link->reference().' ('.implode(' · ', array_filter([$link->utm_source, $link->utm_medium, $link->utm_campaign, $link->utm_content], fn (string $tag): bool => $tag !== '')).')';

        if ($this->links->hasVisits($link)) {
            return ['text' => 'Showing campaign performance for '.$named.'. '.($has ? 'Its row is marked.' : 'Nobody came through it in '.$filter->period->range().'.'), 'visits' => true];
        }

        return [
            'text' => 'No visits have arrived through '.$named.' yet. '.($has
                ? 'Below is the existing campaign '.$link->utm_campaign.', which uses the same utm_campaign.'
                : 'It will appear here after its first visit.'),
            'visits' => false,
        ];
    }

    /**
     * The segments of the paid source the page is filtered by — none while
     * no paid source is chosen: All of its clicks, each segment and the
     * clicks of no segment, each row with its rule and what it is, its
     * visitors, game starts, accounts, accounts per new visitor (toned by
     * the verdict), activated users, what an account cost and — a segment's
     * — the verdict against the whole source; what the card says under
     * them; and the drawer the address opens, a new segment's or one of
     * those listed. They read the period and device: the campaign chosen
     * doesn't narrow them, and the card says so.
     *
     * @param  Collection<int, TrafficSource>  $sources
     * @return array<string, mixed>|null
     */
    private function segmentsOf(GrowthAnalyticsPage $page, AnalyticsFilter $filter, Collection $sources): ?array
    {
        $sourceId = $filter->sourceKey === null ? null : AnalyticsSource::paidSourceId($filter->sourceKey);
        $source = $sourceId === null ? null : $sources->firstWhere('id', $sourceId);

        if ($sourceId === null || ! $source instanceof TrafficSource) {
            return null;
        }

        $models = $this->segments->of($sourceId);
        $rules = [];

        foreach ($models as $segment) {
            $rules[(int) $segment->id] = SegmentRules::stored($segment->rules);
        }

        $figures = $this->segments->figures($sourceId, $filter, $rules);
        $refs = $this->segments->campaignRefs(array_values(array_unique(array_merge([], ...array_map(SegmentRules::campaignIds(...), array_values($rules))))));
        $least = (int) config('analytics.segment_min_visitors');
        $leastAccounts = (int) config('analytics.segment_min_source_accounts');
        $all = $figures['all'];
        $paid = TrafficMoney::of($all->spend)->isPositive();
        $name = $source->name;

        $rows = [self::segmentRow(['kind' => 'all', 'id' => 'all', 'name' => 'All '.$name, 'meta' => 'Every visit of '.$name, 'chips' => [], 'edit' => null], $all, null, $least, $paid)];

        foreach ($models as $segment) {
            $id = (int) $segment->id;
            $own = $figures['segments'][$id] ?? new VisitorFigures;
            $created = $segment->created_at;
            $rows[] = self::segmentRow([
                'kind' => 'segment',
                'id' => 'segment-'.$id,
                'name' => $segment->name,
                'meta' => 'Created '.$created->format($created->year === $filter->period->today->year ? 'd M' : 'd M Y'),
                'chips' => SegmentRules::chips($rules[$id], $refs),
                'edit' => $id,
            ], $own, SegmentRules::verdict($own, $all, $least, $leastAccounts), $least, $paid);
        }

        if ($models->isNotEmpty()) {
            $rows[] = self::segmentRow(['kind' => 'rest', 'id' => 'rest', 'name' => 'Not in any segment', 'meta' => 'Visits no segment matches', 'chips' => [], 'edit' => null], $figures['rest'], null, $least, $paid);
        }

        $base = $all->accounts >= max(1, $leastAccounts) ? $all->accountRate() : null;
        $foot = array_values(array_filter([
            ($models->isNotEmpty() ? 'Segments can overlap, so their rows don’t add up to All '.$name.'. ' : '')
                .($base === null
                    ? 'Verdicts wait until '.$name.' has '.$leastAccounts.' accounts in these days.'
                    : 'Verdicts compare each segment with all '.$name.' traffic: '.self::percent($base).' accounts per new visitor.'),
            'A rate shows “—”, and a segment has too little data for a verdict, until it has '.$least.' new visitors.',
            $paid ? null : 'Cost per registration needs spend, and '.$name.' spent nothing in these days.',
            $filter->campaignKey === null ? null : 'Segments read the period and device; the campaign chosen doesn’t narrow them.',
        ]));
        $editing = match (true) {
            $page->segment === 'new' => 'new',
            is_string($page->segment) && ctype_digit($page->segment) && $models->contains('id', (int) $page->segment) => (int) $page->segment,
            default => null,
        };

        return [
            'source' => $sourceId,
            'name' => $name,
            'title' => $name.' segments',
            'description' => 'Your own slices of '.$name.' traffic, by the parameters its links carry. They count past visits too, since every visit keeps its parameters.',
            'settings' => TrafficSourcesPage::getUrl(['tab' => 'sources', 'edit' => $sourceId]),
            'report' => TrafficSourcesPage::getUrl(['source' => $sourceId]),
            'rows' => $rows,
            'count' => $models->count(),
            'full' => $models->count() >= SaveTrafficSegmentAction::MOST_SEGMENTS,
            'most' => SaveTrafficSegmentAction::MOST_SEGMENTS,
            'foot' => $foot,
            'titles' => self::headerTitles((int) config('analytics.activation_days')),
            'editing' => $editing,
        ];
    }

    /**
     * A row of a source's segments: what it is, then its cells — Visitors,
     * Game starts, Registrations, Reg. rate, Activated, Cost / reg. — and a
     * segment's verdict. The rate is of new visitors, "—" below the
     * segments' denominator; a Strong segment's reads as success, a block
     * candidate's as danger.
     *
     * @param  array{kind: string, id: string, name: string, meta: string, chips: list<string>, edit: int|null}  $line
     * @return array<string, mixed>
     */
    private static function segmentRow(array $line, VisitorFigures $figures, ?SegmentVerdict $verdict, int $least, bool $paid): array
    {
        $rate = $figures->accountRate($least);
        $spend = TrafficMoney::of($figures->spend);

        return [
            ...$line,
            'cells' => [
                'visitors' => number_format($figures->visitors),
                'starters' => number_format($figures->starters),
                'accounts' => number_format($figures->accounts),
                'rate' => [
                    'value' => $rate === null ? '—' : self::percent($rate),
                    'tone' => match ($verdict) {
                        SegmentVerdict::Strong => 'success',
                        SegmentVerdict::Block => 'danger',
                        default => null,
                    },
                    'title' => $rate === null
                        ? number_format($figures->newVisitors).' new '.str('visitor')->plural($figures->newVisitors).', fewer than a rate needs'
                        : number_format($figures->accounts).' of '.number_format($figures->newVisitors).' new visitors',
                ],
                'activated' => [
                    'value' => number_format($figures->activated),
                    'tone' => null,
                    // The segments' figures carry no matured accounts, so no
                    // "too early" here: the header's title says what counts.
                    'title' => number_format($figures->activated).' of '.number_format($figures->accounts).' '.str('account')->plural($figures->accounts).' came back and acted',
                ],
                'cost' => $paid && $figures->accounts > 0 && $spend->isPositive()
                    ? TrafficMoney::fine($spend->dividedBy($figures->accounts, TrafficMoney::SCALE, RoundingMode::HalfUp))
                    : '—',
            ],
            'verdict' => $verdict === null ? null : ['label' => $verdict->label(), 'tone' => match ($verdict) {
                SegmentVerdict::Strong => 'success',
                SegmentVerdict::Block => 'danger',
                default => 'neutral',
            }],
        ];
    }

    /**
     * Campaign performance: the campaigns, the open ones' creatives and the
     * open creatives' games, in the order the sorting asks, with the rows
     * that count the rest — and what the card says around them. The campaign
     * chosen is marked once a creative of it is named, or a tracking link
     * visits came through.
     *
     * @return array<string, mixed>
     */
    private function tree(GrowthAnalyticsPage $page, AnalyticsFilter $filter, int $minimum, bool $linked = false): array
    {
        $period = $filter->period;
        $table = $this->visits->by($filter, AnalyticsDimension::Campaign, self::CAMPAIGNS, unnamed: AnalyticsVisitsQuery::UNNAMED_LEFT_OUT);
        $keys = array_values(array_filter(array_map(fn (array $row): int|string|null => $row['keys']['campaign_key'], $table['rows']), 'is_string'));
        $waiting = $this->waiting($filter);
        $spent = [];

        foreach ($this->visits->spend($filter, [AnalyticsDimension::Campaign]) as $group) {
            $spent[(string) $group['keys']['campaign_key']] = ['spend' => $group['spend'], 'estimated' => $group['estimated']];
        }

        // What a campaign spent is what its clicks cost and what none of its visits took.
        $campaignSpend = function (string $key) use ($spent, $waiting): array {
            $id = CampaignKey::paidCampaignId($key);
            $clicks = $spent[$key] ?? ['spend' => TrafficMoney::stored(0), 'estimated' => TrafficMoney::stored(0)];
            $idle = $id === null ? TrafficMoney::stored(0) : ($waiting[$id] ?? TrafficMoney::stored(0));

            return ['spend' => TrafficMoney::stored(TrafficMoney::of($clicks['spend'])->plus(TrafficMoney::of($idle))), 'estimated' => $clicks['estimated'], 'waiting' => $idle];
        };
        $campaigns = [];

        foreach ($table['rows'] as $row) {
            $key = (string) $row['keys']['campaign_key'];
            $campaigns[] = ['key' => $key, 'figures' => $row['figures'], ...$campaignSpend($key)];
        }

        // The rest: the clicks of every campaign not listed, and the spend none of their visits took, on a row of its own.
        $restSpend = TrafficMoney::of(0);
        $restEstimated = TrafficMoney::of(0);

        foreach ($spent as $key => $sum) {
            if (! in_array((string) $key, $keys, true)) {
                $restSpend = $restSpend->plus(TrafficMoney::of($sum['spend']));
                $restEstimated = $restEstimated->plus(TrafficMoney::of($sum['estimated']));
            }
        }

        $listedIds = array_values(array_filter(array_map(fn (string $key): ?int => CampaignKey::paidCampaignId($key), $keys)));
        $idleElsewhere = TrafficMoney::of(0);

        foreach ($waiting as $id => $amount) {
            if (in_array($id, $listedIds, true)) {
                continue;
            }

            // A campaign past the listed ones that had clicks keeps its spend no visit took yet in the rest's row; only one nobody came through is idle.
            if ($table['rest'] !== null && array_key_exists((string) CampaignKey::paid($id), $spent)) {
                $restSpend = $restSpend->plus(TrafficMoney::of($amount));
            } else {
                $idleElsewhere = $idleElsewhere->plus(TrafficMoney::of($amount));
            }
        }

        $hasSpend = array_filter($campaigns, fn (array $campaign): bool => TrafficMoney::of($campaign['spend'])->isPositive()) !== [] || $restSpend->isPositive();
        $sorts = $hasSpend ? self::SORTS : array_diff_key(self::SORTS, ['cost' => true]);
        $sort = array_key_exists((string) $page->csort, $sorts) ? (string) $page->csort : 'visitors';

        // The campaign the most people came through opens to begin with, and its biggest creative — or the one the address names.
        $marked = $filter->campaignKey !== null && $page->creative !== '' ? $page->creative : null;
        $firstCampaign = $keys[0] ?? null;
        $toggled = array_flip(array_filter($page->toggled, 'is_string'));
        $isOpen = fn (string $node, bool $first): bool => $first xor array_key_exists($node, $toggled);
        $open = array_values(array_filter($keys, fn (string $key): bool => $isOpen(self::node($key), $key === $firstCampaign)));

        $creatives = [];

        foreach ($open as $key) {
            // The creative the address names is listed even past the first ones, so it can open and be marked.
            $keep = $marked !== null && $key === $filter->campaignKey ? [['creative_key' => $marked === CampaignKey::NO_CREATIVE ? null : $marked]] : [];
            $creatives[$key] = $this->visits->by($filter->within(AnalyticsDimension::Campaign, ['campaign_key' => $key]), AnalyticsDimension::Creative, self::CREATIVES, keep: $keep);
        }

        $creativeSpend = [];

        foreach ($this->visits->spend($filter, [AnalyticsDimension::Campaign, AnalyticsDimension::Creative], $open) as $group) {
            $creativeSpend[(string) $group['keys']['campaign_key']][CampaignKey::creative(self::text($group['keys']['creative_key']))] = ['spend' => $group['spend'], 'estimated' => $group['estimated']];
        }

        // Which creatives are open: the biggest of the first campaign, or the one the address names, and those toggled.
        $openCreatives = [];

        foreach ($creatives as $key => $split) {
            $names = array_map(fn (array $row): string => CampaignKey::creative(self::text($row['keys']['creative_key'])), $split['rows']);
            $defaultCreative = $key !== $firstCampaign ? null : ($marked !== null && in_array($marked, $names, true) ? $marked : ($names[0] ?? null));

            foreach ($split['rows'] as $row) {
                $creative = CampaignKey::creative(self::text($row['keys']['creative_key']));

                if ($isOpen(self::node($key, $creative), $creative === $defaultCreative)) {
                    $openCreatives[] = ['campaign' => $key, 'creative' => $creative, 'creativeKey' => self::text($row['keys']['creative_key'])];
                }
            }
        }

        $openNodes = array_flip(array_map(fn (array $node): string => self::node($node['campaign'], $node['creative']), $openCreatives));
        $games = [];

        foreach ($openCreatives as $node) {
            $within = $filter->within(AnalyticsDimension::Campaign, ['campaign_key' => $node['campaign']])->within(AnalyticsDimension::Creative, ['creative_key' => $node['creativeKey']]);
            $games[self::node($node['campaign'], $node['creative'])] = $this->visits->by($within, AnalyticsDimension::Game, self::GAMES, unnamed: AnalyticsVisitsQuery::UNNAMED_LEFT_OUT);
        }

        $gameSpend = [];

        foreach ($this->visits->spend($filter, [AnalyticsDimension::Campaign, AnalyticsDimension::Creative, AnalyticsDimension::Game], array_values(array_unique(array_column($openCreatives, 'campaign')))) as $group) {
            if ($group['keys']['game_id'] !== null) {
                $gameSpend[self::node((string) $group['keys']['campaign_key'], CampaignKey::creative(self::text($group['keys']['creative_key'])))][(int) $group['keys']['game_id']] = ['spend' => $group['spend'], 'estimated' => $group['estimated']];
            }
        }

        $gameIds = [];

        foreach ($games as $split) {
            foreach ($split['rows'] as $row) {
                $gameIds[] = (int) $row['keys']['game_id'];
            }
        }

        $gameModels = $gameIds === [] ? new Collection : Game::query()->whereKey(array_values(array_unique($gameIds)))->get(['id', 'title', 'status'])->keyBy('id');
        $paidIds = array_values(array_unique($listedIds));
        $models = $paidIds === [] ? new Collection : TrafficCampaign::query()->whereKey($paidIds)->get(['id', 'name', 'external_id', 'source_id'])->keyBy('id');
        $sourcesOf = [];

        foreach ($this->visits->pairs($filter, AnalyticsDimension::Campaign, AnalyticsDimension::Source, $keys) as $pair) {
            $sourcesOf[(string) $pair['keys']['campaign_key']][] = (string) $pair['keys']['source_key'];
        }

        $sourceNames = $this->view->sourceNames(array_merge([], ...array_values($sourcesOf)));
        $previous = $period->previous();
        $firstDays = $this->visits->firstDays(AnalyticsDimension::Campaign, $keys, $previous->fromDay ?? $period->fromDay, $period->toDay);
        $rows = [];
        $early = false;

        foreach (self::sorted($campaigns, $sort, $minimum) as $campaign) {
            $key = $campaign['key'];
            $paid = TrafficMoney::of($campaign['spend'])->isPositive();
            $campaignOpen = in_array($key, $open, true);
            $id = CampaignKey::paidCampaignId($key);
            $model = $id === null ? null : $models->get($id);
            $line = self::row($campaign['figures'], $campaign['spend'], $campaign['estimated'], $paid, $minimum, [
                'level' => 0,
                'kind' => 'campaign',
                'id' => 'campaign-'.md5($key),
                'name' => match (true) {
                    $model instanceof TrafficCampaign => $model->label(),
                    default => CampaignKey::label($key),
                },
                'sub' => implode(' · ', array_filter([
                    $model instanceof TrafficCampaign ? 'ID '.$model->external_id : ($id === null ? 'utm_campaign' : null),
                    self::since($firstDays[$key] ?? null, $period->fromDay, $period->today),
                ])),
                'mono' => false,
                'source' => self::sourceText($sourcesOf[$key] ?? [], $sourceNames),
                'open' => $campaignOpen,
                'toggle' => ['campaign' => $key, 'creative' => ''],
                'url' => null,
                'marked' => ($marked !== null || $linked) && $key === $filter->campaignKey,
            ]);
            $early = $early || $line['tooEarly'];
            $rows[] = $line;

            if (! $campaignOpen) {
                continue;
            }

            $split = $creatives[$key];
            $best = self::best($split['rows'], $minimum);
            $items = array_map(function (array $row) use ($creativeSpend, $key): array {
                $creative = CampaignKey::creative(self::text($row['keys']['creative_key']));

                return [
                    'figures' => $row['figures'],
                    'creative' => $creative,
                    'creativeKey' => self::text($row['keys']['creative_key']),
                    ...($creativeSpend[$key][$creative] ?? ['spend' => TrafficMoney::stored(0), 'estimated' => TrafficMoney::stored(0)]),
                ];
            }, $split['rows']);

            foreach (self::sorted($items, $sort, $minimum) as $item) {
                $node = self::node($key, $item['creative']);
                $creativeOpen = array_key_exists($node, $openNodes);
                $line = self::row($item['figures'], $item['spend'], $item['estimated'], $paid, $minimum, [
                    'level' => 1,
                    'kind' => 'creative',
                    'id' => 'creative-'.md5($node),
                    'name' => $item['creative'],
                    'sub' => null,
                    'mono' => $item['creativeKey'] !== null,
                    'source' => ['text' => $item['creativeKey'] === null ? '' : ($id === null ? 'utm_content or utm_term' : 'Creative ID'), 'more' => null],
                    'open' => $creativeOpen,
                    'toggle' => ['campaign' => $key, 'creative' => $item['creative']],
                    'url' => null,
                    'marked' => $marked !== null && $key === $filter->campaignKey && $item['creative'] === $marked,
                    'best' => $best !== null && $best === $item['creative'],
                ]);
                $early = $early || $line['tooEarly'];
                $rows[] = $line;

                if (! $creativeOpen) {
                    continue;
                }

                foreach ($games[$node]['rows'] ?? [] as $row) {
                    $gameId = (int) $row['keys']['game_id'];
                    $game = $gameModels->get($gameId);
                    $money = $gameSpend[$node][$gameId] ?? ['spend' => TrafficMoney::stored(0), 'estimated' => TrafficMoney::stored(0)];
                    $offered = $game instanceof Game && $game->status !== GameStatus::Archived;
                    $line = self::row($row['figures'], $money['spend'], $money['estimated'], $paid, $minimum, [
                        'level' => 2,
                        'kind' => 'game',
                        'id' => 'game-'.md5($node).'-'.$gameId,
                        'name' => $game instanceof Game ? $game->title : 'Game #'.$gameId,
                        'sub' => $offered ? 'Open game analytics' : ($game instanceof Game ? 'Archived' : 'Deleted'),
                        'mono' => false,
                        'source' => ['text' => '', 'more' => null],
                        'open' => null,
                        'toggle' => null,
                        'url' => $offered ? GamesTab::url($gameId, $filter) : null,
                        'marked' => false,
                    ]);
                    $early = $early || $line['tooEarly'];
                    $rows[] = $line;
                }

                if (($games[$node]['rest'] ?? null) !== null) {
                    $restMoney = self::restOf($gameSpend[$node] ?? [], array_map(fn (array $row): string => (string) $row['keys']['game_id'], $games[$node]['rows']));
                    $rows[] = self::row($games[$node]['rest']['figures'], $restMoney['spend'], $restMoney['estimated'], $paid, $minimum, self::restLine(2, 'games-'.md5($node), 'Other games', 'Every game past the first '.self::GAMES.', each visitor once'));
                }
            }

            if ($split['rest'] !== null) {
                $restMoney = self::restOf($creativeSpend[$key] ?? [], array_column($items, 'creative'));
                $rows[] = self::row($split['rest']['figures'], $restMoney['spend'], $restMoney['estimated'], $paid, $minimum, self::restLine(1, 'creatives-'.md5($key), 'Other creatives', 'Every creative past the first '.self::CREATIVES.', each visitor once'));
            }

            if (TrafficMoney::of($campaign['waiting'])->isPositive()) {
                $rows[] = self::idleLine(1, 'idle-'.md5($key), $campaign['waiting'], 'Not shared over its visits yet, or on days without one');
            }
        }

        if ($table['rest'] !== null) {
            $rows[] = self::row($table['rest']['figures'], (string) $restSpend, (string) $restEstimated, $restSpend->isPositive(), $minimum, self::restLine(0, 'campaigns', 'Other campaigns', 'Every campaign past the first '.self::CAMPAIGNS.', each visitor once'));
        } else {
            // With no row for the rest, what the clicks of a campaign nobody came through cost — crawlers', pages that never ran — goes with the spend no visit took.
            $idleElsewhere = $idleElsewhere->plus($restSpend);
        }

        if ($idleElsewhere->isPositive() && $rows !== []) {
            $rows[] = self::idleLine(0, 'idle', (string) $idleElsewhere, 'Campaigns no visitor here came through');
        }

        return [
            'description' => 'Click a campaign to see its creatives, then a creative to see its games. '.($period->isCustom() ? $period->range() : $period->label()).'.',
            'sorts' => $sorts,
            'sort' => $sort,
            'rows' => $campaigns === [] ? [] : $rows,
            'foot' => self::foot($filter, $minimum, $early),
            'titles' => self::headerTitles((int) config('analytics.activation_days')),
        ];
    }

    /**
     * A row of the tree: what it is and where it leads, then its cells —
     * Spend, Landing visitors, Cost / visitor, Game starts, Completion,
     * Registrations, Cost / registration, Activated, Cost / activated.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private static function row(VisitorFigures $figures, string $spend, string $estimated, bool $paid, int $minimum, array $line): array
    {
        $money = TrafficMoney::of($spend === '' ? 0 : $spend);
        $cost = fn (int $count): array => ! $paid || $count === 0
            ? self::cell('—', quiet: true)
            : self::cell(TrafficMoney::fine($money->dividedBy($count, TrafficMoney::SCALE, RoundingMode::HalfUp)));
        $tooEarly = self::tooEarly($figures) && $figures->activationRate($minimum) !== null;
        $ofNew = fn (?float $rate): ?string => $rate === null ? null : self::percent($rate).' of new visitors';
        $completion = $figures->completion($minimum);

        return [
            'best' => false,
            ...$line,
            'tooEarly' => $tooEarly,
            'cells' => [
                $paid
                    ? self::cell(TrafficMoney::format($money), sub: TrafficMoney::of($estimated === '' ? 0 : $estimated)->isPositive() ? 'estimated' : null, title: TrafficMoney::of($estimated === '' ? 0 : $estimated)->isPositive() ? TrafficMoney::format($estimated).' of it estimated' : null, strong: true, tag: true)
                    : self::cell('Unpaid', quiet: true, small: true),
                self::cell(number_format($figures->visitors), strong: true),
                $cost($figures->visitors),
                self::cell(number_format($figures->starters), sub: ($rate = $figures->startRate($minimum)) === null ? null : self::percent($rate).' of visitors'),
                $completion === null
                    ? self::cell('—', quiet: true)
                    : self::cell(self::percent($completion), title: number_format($figures->completers).' of '.number_format($figures->starters).' starters'),
                self::cell(number_format($figures->accounts), sub: $ofNew($figures->accountRate($minimum))),
                $cost($figures->accounts),
                self::cell(
                    number_format($figures->activated),
                    sub: $tooEarly ? ($figures->activated === 0 ? 'Too early' : 'so far') : $ofNew($figures->activationRate($minimum)),
                    title: $tooEarly ? number_format($figures->accounts).' '.str('account')->plural($figures->accounts).', none old enough yet'.($figures->activated === 0 ? '' : ': more may still activate') : null,
                ),
                $cost($figures->activated),
            ],
        ];
    }

    /**
     * A cell of the tree: its figure, a line under it, what it is on hover,
     * and how it reads — quiet, strong, small, or with its line as a tag.
     *
     * @return array{value: string, sub: string|null, title: string|null, quiet: bool, strong: bool, small: bool, tag: bool}
     */
    private static function cell(string $value, ?string $sub = null, ?string $title = null, bool $quiet = false, bool $strong = false, bool $small = false, bool $tag = false): array
    {
        return ['value' => $value, 'sub' => $sub, 'title' => $title, 'quiet' => $quiet, 'strong' => $strong, 'small' => $small, 'tag' => $tag];
    }

    /**
     * What the row counting the rest of a level is: named, said what it is,
     * opening nothing.
     *
     * @return array<string, mixed>
     */
    private static function restLine(int $level, string $id, string $name, string $sub): array
    {
        return ['level' => $level, 'kind' => 'rest', 'id' => 'rest-'.$id, 'name' => $name, 'sub' => $sub, 'mono' => false, 'source' => ['text' => '', 'more' => null], 'open' => null, 'toggle' => null, 'url' => null, 'marked' => false];
    }

    /**
     * The row of what was spent with no visit to take it: its spend, nothing
     * else to count.
     *
     * @return array<string, mixed>
     */
    private static function idleLine(int $level, string $id, string $amount, string $sub): array
    {
        $none = self::cell('', quiet: true);

        return [
            'level' => $level, 'kind' => 'idle', 'id' => $id, 'name' => 'Spend with no visits', 'sub' => $sub, 'mono' => false,
            'source' => ['text' => '', 'more' => null], 'open' => null, 'toggle' => null, 'url' => null, 'marked' => false, 'best' => false, 'tooEarly' => false,
            'cells' => [self::cell(TrafficMoney::format($amount), strong: true), ...array_fill(0, 8, $none)],
        ];
    }

    /**
     * Whether a row's activation is no rate yet: it has accounts, and none of
     * them has had its days to activate. A row with no account at all reads
     * its rate, none activated.
     */
    private static function tooEarly(VisitorFigures $figures): bool
    {
        return $figures->accounts > 0 && $figures->maturedAccounts === 0;
    }

    /**
     * The creative of a campaign with the best activation rate — activated
     * users of new visitors — among those with the visitors a creative needs
     * to be named its best and a rate that can be told — not too early; one
     * with no account reads none activated — once at least two have them;
     * none while none of them has activated anyone. A tie goes to the
     * creative with more people.
     *
     * @param  list<array{keys: array<string, int|string|null>, figures: VisitorFigures, visits: int, tagged: int}>  $creatives  the most people first
     */
    private static function best(array $creatives, int $minimum): ?string
    {
        $least = (int) config('analytics.best_creative_min_visitors');
        $rated = [];

        foreach ($creatives as $row) {
            $figures = $row['figures'];
            $rate = $figures->activationRate($minimum);

            if ($row['keys']['creative_key'] !== null && $figures->visitors >= $least && $rate !== null && ! self::tooEarly($figures)) {
                $rated[] = ['creative' => (string) $row['keys']['creative_key'], 'rate' => $rate];
            }
        }

        if (count($rated) < 2) {
            return null;
        }

        $best = $rated[0];

        foreach ($rated as $each) {
            if ($each['rate'] > $best['rate']) {
                $best = $each;
            }
        }

        return $best['rate'] > 0 ? $best['creative'] : null;
    }

    /**
     * Rows in the order the sorting asks: by people, or by a rate of new
     * visitors — highest first, a rate that can't be shown last, activation
     * "Too early" among them — or by what an account cost, cheapest first, a
     * row with no spend or no account last; ties keep the most people first.
     *
     * @template T of array{figures: VisitorFigures, spend: string}
     *
     * @param  list<T>  $rows  the most people first
     * @return list<T>
     */
    private static function sorted(array $rows, string $sort, int $minimum): array
    {
        if ($sort === 'visitors') {
            return $rows;
        }

        $value = fn (array $row): ?float => match ($sort) {
            'registration' => $row['figures']->accountRate($minimum),
            'activation' => self::tooEarly($row['figures']) ? null : $row['figures']->activationRate($minimum),
            default => $row['figures']->accounts === 0 || ! TrafficMoney::of($row['spend'])->isPositive()
                ? null
                : -1 * (float) (string) TrafficMoney::of($row['spend'])->dividedBy($row['figures']->accounts, TrafficMoney::SCALE, RoundingMode::HalfUp),
        };
        $indexed = array_map(fn (array $row, int $index): array => [$row, $value($row), $index], $rows, array_keys($rows));

        usort($indexed, fn (array $a, array $b): int => match (true) {
            $a[1] === null && $b[1] === null => $a[2] <=> $b[2],
            $a[1] === null => 1,
            $b[1] === null => -1,
            default => [$b[1], $a[2]] <=> [$a[1], $b[2]],
        });

        return array_map(fn (array $each): array => $each[0], $indexed);
    }

    /**
     * Where a campaign's people came from, as the Source · medium column
     * names it: the source most of them came through, and how many more.
     *
     * @param  list<string>  $keys  the most people first
     * @param  array<int, string>  $names  the paid sources' names, by id
     * @return array{text: string, more: string|null}
     */
    private static function sourceText(array $keys, array $names): array
    {
        if ($keys === []) {
            return ['text' => '—', 'more' => null];
        }

        $more = count($keys) - 1;

        return ['text' => AnalyticsView::sourceLabel($keys[0], $names), 'more' => $more > 0 ? 'and '.$more.' more '.str('source')->plural($more) : null];
    }

    /** When a campaign began, as its row says it: since its first day in the period, or Always on when it came before. */
    private static function since(?string $firstDay, string $fromDay, CarbonImmutable $today): ?string
    {
        if ($firstDay === null) {
            return null;
        }

        if ($firstDay < $fromDay) {
            return 'Always on';
        }

        $day = CarbonImmutable::parse($firstDay, 'UTC');

        return 'since '.$day->format($day->year === $today->year ? 'd M' : 'd M Y');
    }

    /**
     * What the creatives past those listed spent: every group of the
     * campaign's not among them.
     *
     * @param  array<int|string, array{spend: string, estimated: string}>  $spend  by creative as a row names it, or by game
     * @param  list<string>  $listed
     * @return array{spend: string, estimated: string}
     */
    private static function restOf(array $spend, array $listed): array
    {
        $total = TrafficMoney::of(0);
        $estimated = TrafficMoney::of(0);

        foreach ($spend as $creative => $sum) {
            if (! in_array((string) $creative, $listed, true)) {
                $total = $total->plus(TrafficMoney::of($sum['spend']));
                $estimated = $estimated->plus(TrafficMoney::of($sum['estimated']));
            }
        }

        return ['spend' => (string) $total, 'estimated' => (string) $estimated];
    }

    /**
     * The spend none of a paid campaign's visits took in the period — a
     * network's day not yet shared over them, or a day without any — by
     * campaign id, for the campaigns the filter can reach: none while a
     * device is chosen, since that spend has none, nor for a source or
     * campaign that isn't paid.
     *
     * @return array<int, string>
     */
    private function waiting(AnalyticsFilter $filter): array
    {
        $sourceId = $filter->sourceKey === null ? null : AnalyticsSource::paidSourceId($filter->sourceKey);
        $campaignId = $filter->campaignKey === null ? null : CampaignKey::paidCampaignId($filter->campaignKey);

        if ($filter->device !== null || ($filter->sourceKey !== null && $sourceId === null) || ($filter->campaignKey !== null && $campaignId === null)) {
            return [];
        }

        $waiting = [];

        foreach ($this->spend->unallocated($filter->fromDay(), $filter->toDay(), $sourceId) as $row) {
            if ($row['campaign_id'] !== null && ($campaignId === null || $row['campaign_id'] === $campaignId)) {
                $waiting[$row['campaign_id']] = $row['amount'];
            }
        }

        return $waiting;
    }

    /**
     * Where spend comes from: each way the paid sources count what they
     * spent — with the sources that count that way and the campaigns of
     * theirs that count it their own way — and what the networks' APIs
     * report.
     *
     * @param  Collection<int, TrafficSource>  $sources
     * @return array{lines: list<array{mode: string, note: string, who: string}>, band: array{tone: string, icon: string, title: string, text: string}, url: string}
     */
    private function spendOrigin(Collection $sources): array
    {
        $own = TrafficCampaign::query()->whereNotNull('spend_mode')->whereNull('archived_at')->pluck('spend_mode')
            ->countBy(fn (mixed $mode): string => $mode instanceof TrafficSpendMode ? $mode->value : (string) $mode);
        $lines = [];

        foreach (TrafficSpendMode::cases() as $mode) {
            $names = $sources->filter(fn (TrafficSource $source): bool => $source->spend_mode === $mode && $source->status !== TrafficSourceStatus::Ended)->pluck('name')->values()->all();
            $campaigns = (int) ($own[$mode->value] ?? 0);

            if ($names === [] && $campaigns === 0) {
                continue;
            }

            $who = array_slice($names, 0, self::NAMED_SOURCES);
            $more = count($names) - count($who);
            $lines[] = [
                'mode' => $mode->label(),
                'note' => self::MODE_NOTES[$mode->value],
                'who' => implode(', ', array_filter([
                    $who === [] ? null : implode(', ', $who).($more > 0 ? ' and '.$more.' more' : ''),
                    $campaigns > 0 ? $campaigns.' '.str('campaign')->plural($campaigns).' of '.($campaigns === 1 ? 'its' : 'their').' own' : null,
                ])),
            ];
        }

        $connections = $sources->map(fn (TrafficSource $source): ?TrafficConnectionStatus => $source->apiConnection?->status)->filter();
        $connected = $sources->filter(fn (TrafficSource $source): bool => $source->apiConnection?->status === TrafficConnectionStatus::Connected)->pluck('name')->values()->all();
        $attention = $connections->filter(fn (TrafficConnectionStatus $status): bool => $status === TrafficConnectionStatus::NeedsAttention)->count();

        $band = match (true) {
            $sources->isEmpty() => ['tone' => 'neutral', 'icon' => 'unplug', 'title' => 'No paid source yet.', 'text' => 'Unpaid visitors cost nothing, so there is no spend to count. Conversion numbers come from '.site_name().' either way.'],
            $attention > 0 => ['tone' => 'warning', 'icon' => 'unplug', 'title' => $attention.' API '.str('connection')->plural($attention).' '.($attention === 1 ? 'needs' : 'need').' attention.', 'text' => 'Spend from '.($attention === 1 ? 'that network' : 'those networks').' stays estimated until it reports again. Conversion numbers still come from '.site_name().'.'],
            $connected !== [] => ['tone' => 'info', 'icon' => 'plug', 'title' => 'Network API connected.', 'text' => implode(', ', $connected).' '.(count($connected) === 1 ? 'reports its' : 'report their').' spend; the other sources count it as above. Conversion numbers come from '.site_name().'.'],
            default => ['tone' => 'neutral', 'icon' => 'unplug', 'title' => 'No network API connected.', 'text' => 'Spend comes from what '.site_name().' records: each click’s price, your rates, fixed prices and the figures you type in. Conversion numbers come from '.site_name().' too.'],
        };

        return ['lines' => $lines, 'band' => $band, 'url' => TrafficSourcesPage::getUrl(['tab' => 'spend'])];
    }

    /**
     * Paid sources: each source set up under Traffic sources — one ended
     * only while it still brought someone or spent anything — with the
     * visitors it brought in the period on the device chosen, what it spent
     * there (the spend none of its visits took too, while no device is
     * chosen), how many segments it has, the way to its settings and to its
     * segments, and whether the page is filtered by it. The most people
     * first.
     *
     * @param  Collection<int, TrafficSource>  $sources
     * @return array{rows: list<array<string, mixed>>, description: string, setUp: string}
     */
    private function paidSources(Collection $sources, AnalyticsFilter $filter): array
    {
        $base = $filter->periodAndDevice();
        $keys = $sources->map(fn (TrafficSource $source): string => AnalyticsSource::paid((int) $source->id))->values()->all();
        $people = $this->visits->of($base, AnalyticsDimension::Source, $keys);
        $spent = [];

        foreach ($this->visits->spend($base, [AnalyticsDimension::Source], $keys) as $group) {
            $spent[(string) $group['keys']['source_key']] = TrafficMoney::of($group['spend']);
        }

        if ($base->device === null && $sources->isNotEmpty()) {
            foreach ($this->spend->unallocated($base->fromDay(), $base->toDay()) as $row) {
                $key = AnalyticsSource::paid($row['source_id']);
                $spent[$key] = ($spent[$key] ?? TrafficMoney::of(0))->plus(TrafficMoney::of($row['amount']));
            }
        }

        $rows = [];

        foreach ($sources as $source) {
            $key = AnalyticsSource::paid((int) $source->id);
            $visitors = isset($people[$key]) ? $people[$key]['figures']->visitors : 0;
            $spend = $spent[$key] ?? TrafficMoney::of(0);

            if ($source->status === TrafficSourceStatus::Ended && $visitors === 0 && ! $spend->isPositive()) {
                continue;
            }

            $rows[] = [
                'key' => $key,
                'name' => $source->name,
                'icon' => $source->type === TrafficSourceType::Network ? 'megaphone' : 'newspaper',
                'medium' => AnalyticsSource::medium($key),
                'status' => ['label' => $source->status->label(), 'tone' => match ($source->status) {
                    TrafficSourceStatus::Active => 'success',
                    TrafficSourceStatus::Paused => 'warning',
                    TrafficSourceStatus::Ended => 'neutral',
                }],
                'note' => implode(' · ', array_filter([
                    $source->type->label(),
                    'spend: '.lcfirst($source->spend_mode->label()),
                    $source->type === TrafficSourceType::Network ? $source->campaigns_count.' '.str('campaign')->plural((int) $source->campaigns_count) : null,
                    (int) $source->segments_count > 0 ? $source->segments_count.' '.str('segment')->plural((int) $source->segments_count) : null,
                ])),
                'visitors' => $visitors,
                'people' => $visitors === 0 ? 'No visitors' : number_format($visitors).' '.str('visitor')->plural($visitors),
                'spend' => $spend->isPositive() ? TrafficMoney::format($spend).' spent' : null,
                'settings' => TrafficSourcesPage::getUrl(['tab' => 'sources', 'edit' => $source->id]),
                'selected' => $filter->sourceKey === $key,
            ];
        }

        // The most people first; a stable sort keeps the names' order among equals.
        usort($rows, fn (array $a, array $b): int => $b['visitors'] <=> $a['visitors']);

        return [
            'rows' => $rows,
            'description' => 'The ad networks and placements set up under Traffic sources, with the visitors each brought in '.$filter->period->range()
                .($filter->device === null ? '' : ' on '.strtolower($filter->device->label()))
                .'. Click one to filter the page by it.',
            'setUp' => TrafficSourcesPage::getUrl(['tab' => 'sources']),
        ];
    }

    /**
     * What Registrations and Activated count — the words people read another
     * way: a registration is a confirmed account however it was confirmed, and
     * activation is not that confirmation but what the account did after its
     * sign-up day.
     */
    public static function meaning(int $days): string
    {
        return 'Registrations count confirmed accounts: the email link clicked, or a Google or Facebook sign-up, which confirms at once. '
            .'Activated counts accounts that came back and played, voted, commented or posted on one of the '.$days.' days after their sign-up day; the sign-up day itself doesn’t count.';
    }

    /**
     * The headers whose meaning a pointer can ask for, and what it is.
     *
     * @return array<string, string>
     */
    public static function headerTitles(int $days): array
    {
        return [
            'Registrations' => 'Confirmed accounts: the email link clicked, or a Google or Facebook sign-up, which confirms at once',
            'Activated' => 'Accounts that came back and played, voted, commented or posted on one of the '.$days.' days after their sign-up day',
        ];
    }

    /**
     * What the tree's footnote says: where a campaign and its creative come
     * from, where a game's row leads, how rates are read, when activation
     * can be told, and the spend a device leaves out.
     */
    private static function foot(AnalyticsFilter $filter, int $minimum, bool $early): string
    {
        $days = (int) config('analytics.activation_days');
        $foot = 'A paid campaign and its creative come from the network’s link; a tagged one from utm_campaign, its creative from utm_content, else utm_term. '
            .'Click a game row to open its analytics. '.self::meaning($days).' Registration and activation use new visitors; a rate shows “—” until the number it divides by reaches '.$minimum.'.';

        if ($early) {
            $foot .= ' “Too early” marks a row none of whose accounts has had its '.$days.' days yet; accounts from '
                .ActivityDay::maturedBefore($filter->period->today, $days)->format('d M').' on can still activate.';
        }

        if ($filter->device !== null) {
            $foot .= ' Spend no visit took has no device, so it is left out while one is chosen.';
        }

        return $foot.' Ad networks count clicks differently, so their numbers won’t match these visitors exactly.';
    }

    private static function percent(float $rate): string
    {
        return number_format($rate * 100, 1).'%';
    }

    /** A key's value as text, none for none. */
    private static function text(int|string|null $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
