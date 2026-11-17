<?php

namespace App\Filament\Resources\Games\Pages;

use App\Enums\GameAudience;
use App\Enums\GameFlowStepType;
use App\Enums\GameStepAudience;
use App\Filament\Pages\TrafficSourcesPage;
use App\Filament\Resources\Games\Concerns\InteractsWithGameEditor;
use App\Filament\Resources\Games\GameResource;
use App\Models\Game;
use App\Models\GameTrafficPeriod;
use App\Models\GameVersion;
use App\Models\TrafficSource;
use App\Queries\Games\GameMetricsQuery;
use App\Queries\Traffic\TrafficReportQuery;
use App\Support\Games\Analytics\GameFunnel;
use App\Support\Games\Analytics\GameMetricsScope;
use App\Support\Traffic\Money\TrafficMoney;
use App\Support\Traffic\Reports\ReportFigures;
use App\Support\Traffic\Reports\TwoProportionTest;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * A game's Analytics tab: how far its visitors got — anonymous visitors and
 * logged-in players apart — over a window, for every version or one, against
 * the window before it: the figures and what the visits were worth, the
 * versions on paid traffic compared, the visitors by where they came from,
 * the funnel, how many attempts reached each round, which results players
 * earned, the versions, flows or content sets side by side, and the traffic
 * periods the figures were made in.
 *
 * Playing figures are read from what playing recorded (GameMetricsQuery);
 * worth, spend and where visitors came from from Traffic sources
 * (TrafficReportQuery).
 */
final class GameAnalytics extends Page
{
    use InteractsWithGameEditor;

    /** The windows a game's figures are read over, by days; all time has none. */
    public const PERIODS = ['7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days', 'all' => 'All time'];

    public const BREAKDOWNS = ['version' => 'Version', 'flow' => 'Flow', 'set' => 'Content set'];

    protected static string $resource = GameResource::class;

    protected string $view = 'filament.pages.game-analytics';

    protected Width|string|null $maxContentWidth = 'rg-admin-main';

    #[Url(as: 'group', except: 'anonymous')]
    public string $audience = 'anonymous';

    #[Url(except: '30d')]
    public string $period = '30d';

    #[Url(except: 0)]
    public int $version = 0;

    #[Url(as: 'by', except: 'version')]
    public string $breakdown = 'version';

    /** Versions on paid traffic: every paid source, or one, by id. */
    #[Url(as: 'paid', except: 0)]
    public int $paidSource = 0;

    /** Traffic sources: every device, or one. */
    #[Url(as: 'device', except: 'all')]
    public string $sourcesDevice = 'all';

    public function mount(int|string $record): void
    {
        $this->mountGame($record);
    }

    protected function getViewData(): array
    {
        $game = $this->game();
        abort_if($game === null, 404);

        $audience = GameAudience::tryFrom($this->audience) ?? GameAudience::Anonymous;
        $period = array_key_exists($this->period, self::PERIODS) ? $this->period : '30d';
        $breakdown = array_key_exists($this->breakdown, self::BREAKDOWNS) ? $this->breakdown : 'version';
        /** @var Collection<int, GameVersion> $versions */
        $versions = GameVersion::query()->where('game_id', $game->id)->with(['contentSet', 'flow'])->orderByDesc('number')->get()->keyBy('id');
        $versionId = $versions->has($this->version) ? $this->version : null;
        // Up to and including the current second: the database keeps whole seconds.
        $now = CarbonImmutable::now()->startOfSecond()->addSecond();
        $from = self::windowStart($period, $now);
        // The window before, as long, to compare with; all time has none.
        $before = $period === 'all' ? null : $from->subSeconds((int) $from->diffInSeconds($now));
        $scope = new GameMetricsScope((int) $game->id, $audience, $from, $now, $versionId);
        $metrics = app(GameMetricsQuery::class);
        $reports = app(TrafficReportQuery::class);

        $total = $metrics->funnels($scope)[0];
        $earlier = $before !== null ? $metrics->funnels(new GameMetricsScope((int) $game->id, $audience, $before, $from, $versionId))[0] : null;
        $byVersion = $metrics->funnels($scope, 'version');
        $ofVersion = fn (array $row): bool => $versionId === null || $row['version_id'] === $versionId;
        $traffic = collect($reports->gameBreakdown((int) $game->id, $from, $now))->filter($ofVersion)->values();
        $worth = self::sum($traffic);
        $worthBefore = $before !== null ? self::sum(collect($reports->gameBreakdown((int) $game->id, $before, $from))->filter($ofVersion)) : null;
        $goals = ($versionId !== null ? $versions->only([$versionId]) : $versions)->map(fn (GameVersion $version): string => $version->flowGoal()->label())->unique()->values();
        $window = strtolower(self::PERIODS[$period]);
        // Of the versions the figures are of: the one chosen, or every one.
        $emailVersions = ($versionId !== null ? $versions->only([$versionId]) : $versions)->filter(fn (GameVersion $version): bool => self::asksForEmail($version))->keys()->all();
        $finishedWithEmail = array_sum(array_map(fn (GameFunnel $funnel): int => $funnel->finishers, array_intersect_key($byVersion, array_flip($emailVersions))));
        $focus = $versionId !== null ? $versions->get($versionId) : self::busiest($versions, $byVersion);

        return [
            'header' => $this->editorHeader($game, 'analytics'),
            'audience' => $audience->value,
            'anonymous' => $audience === GameAudience::Anonymous,
            'audiences' => ['anonymous' => 'Anonymous visitors', 'authenticated' => 'Logged-in players'],
            'period' => $period,
            'periods' => self::PERIODS,
            'version' => $versionId ?? 0,
            'versionOptions' => [
                ['value' => '0', 'label' => 'All versions', 'trigger' => 'All'],
                ...$versions->map(fn (GameVersion $version): array => [
                    'value' => (string) $version->id,
                    'label' => $version->label().' · '.$version->contentSet->name.' · '.$version->flow->name,
                    'trigger' => $version->label(),
                ])->values()->all(),
            ],
            'breakdown' => $breakdown,
            'breakdowns' => self::BREAKDOWNS,
            'scopeText' => implode(' · ', [$audience === GameAudience::Anonymous ? 'Anonymous visitors' : 'Logged-in players', self::PERIODS[$period], $versionId !== null ? $versions->get($versionId)?->label() : 'all versions']),
            'datesText' => $before === null ? null : self::dates($from, $now).' · compared with '.self::dates($before, $from),
            'valueUrl' => GameResource::getUrl('value', ['record' => $game]),
            'noVersions' => $versions->isEmpty(),
            'noData' => $total->isEmpty(),
            'kpis' => $audience === GameAudience::Anonymous ? self::anonymousKpis($total, $earlier, $worth, $worthBefore, $goals->count() === 1 ? $goals[0] : 'each flow’s own goal') : self::playerKpis($total, $earlier),
            'funnel' => self::withChanges(
                $audience === GameAudience::Anonymous ? self::anonymousFunnel($total, $finishedWithEmail, $emailVersions !== [], $metrics->signUpMethods($scope)) : self::playerFunnel($total),
                $earlier === null ? null : ($audience === GameAudience::Anonymous ? self::anonymousFunnel($earlier, 0, $emailVersions !== [], []) : self::playerFunnel($earlier)),
            ),
            'compared' => $earlier !== null,
            'focus' => $focus !== null ? self::focus($focus, $metrics, $scope, $byVersion[(int) $focus->id] ?? new GameFunnel, $versionId === null) : null,
            'breakdownRows' => self::breakdownRows($breakdown, $breakdown === 'version' ? $byVersion : $metrics->funnels($scope, $breakdown), $versions, $audience),
            'periodRows' => self::periodRows($game, $audience, $metrics->periods((int) $game->id)),
            'trafficUrl' => GameResource::getUrl('traffic', ['record' => $game]),
            'versionsUrl' => GameResource::getUrl('versions', ['record' => $game]),
            'paid' => self::paidVersions($game, $traffic, $versions, $this->paidSource, $window),
            'sources' => self::sourceRows($game, $traffic, $this->sourcesDevice, $reports->gameCampaigns((int) $game->id, $from, $now)),
            'sourcesDevice' => in_array($this->sourcesDevice, ['mobile', 'desktop'], true) ? $this->sourcesDevice : 'all',
            'window' => $window,
            'gameTitle' => $game->title,
        ];
    }

    /**
     * The versions on paid traffic, of every paid source or one: each
     * version's new visitors, how far they got, its accounts, what an account
     * cost and what a visitor was worth, with the share it has now; and the
     * two that bring the most accounts per new visitor compared — whether one
     * brings more, or it is too early to say (TwoProportionTest).
     *
     * @param  Collection<int, array{channel: string, source_id: int|null, version_id: int|null, device: string, first_at: CarbonImmutable, figures: ReportFigures}>  $traffic
     * @param  Collection<int, GameVersion>  $versions
     * @return array<string, mixed>|null
     */
    private static function paidVersions(Game $game, Collection $traffic, Collection $versions, int $sourceId, string $window): ?array
    {
        $paid = $traffic->where('source_id', '!==', null);

        if ($paid->isEmpty()) {
            return null;
        }

        $names = TrafficSource::query()->whereIn('id', $paid->pluck('source_id')->unique())->orderBy('name')->pluck('name', 'id');
        $sourceId = $names->has($sourceId) ? $sourceId : 0;
        $rows = $sourceId === 0 ? $paid : $paid->where('source_id', $sourceId);
        $shares = GameTrafficPeriod::query()->where('game_id', $game->id)->where('audience', GameAudience::Anonymous->value)->whereNull('ended_at')->with('versions')->first()
            ?->versions->mapWithKeys(fn (GameVersion $version): array => [(int) $version->id => (int) $version->getRelation('pivot')->getAttribute('share_percent')]) ?? collect();
        $byVersion = $rows->where('version_id', '!==', null)->groupBy('version_id')->map(fn (Collection $group): ReportFigures => self::sum($group))
            ->filter(fn (ReportFigures $figures, int|string $id): bool => $versions->has((int) $id) && $figures->newVisitors() > 0)
            ->sortByDesc(fn (ReportFigures $figures): float => $figures->accountRate() ?? 0.0);
        $comparison = null;

        if ($byVersion->count() >= 2) {
            [$a, $b] = [(int) $byVersion->keys()[0], (int) $byVersion->keys()[1]];
            [$fa, $fb] = [$byVersion[$a], $byVersion[$b]];
            $comparison = [
                'a' => $versions[$a]->label(),
                'b' => $versions[$b]->label(),
                'rateA' => self::percent($fa->accounts, $fa->newVisitors()),
                'rateB' => self::percent($fb->accounts, $fb->newVisitors()),
                'earlier' => 'Earlier steps: finished '.self::percent($fa->completed, $fa->opened).' on '.$versions[$a]->label().' vs '.self::percent($fb->completed, $fb->opened).' on '.$versions[$b]->label()
                    .' · flow goal '.self::percent($fa->goal, $fa->opened).' on '.$versions[$a]->label().' vs '.self::percent($fb->goal, $fb->opened).' on '.$versions[$b]->label()
                    .'. They move sooner than accounts, but only accounts are what the site is after.',
                ...TwoProportionTest::compare($fa->newVisitors(), $fa->accounts, $fb->newVisitors(), $fb->accounts),
            ];
        }

        $ahead = $comparison !== null && in_array($comparison['verdict'], ['leader', 'ahead'], true) ? (int) $byVersion->keys()[0] : null;

        return [
            'sources' => ['0' => 'All paid', ...$names->mapWithKeys(fn (string $name, int $id): array => [(string) $id => $name])->all()],
            'source' => (string) $sourceId,
            'note' => 'New visitors from '.($sourceId === 0 ? 'ad networks and banners' : $names[$sourceId]).', '.$window.'. One campaign link for the game: RateGuru picks the version after the click, by the shares in Traffic.',
            'reportUrl' => TrafficSourcesPage::getUrl(array_filter(['game' => $game->id, 'view' => 'versions', 'source' => $sourceId ?: null])),
            'comparison' => $comparison,
            'rows' => $byVersion->map(function (ReportFigures $figures, int|string $id) use ($versions, $shares, $ahead): array {
                $version = $versions[(int) $id];
                $roi = $figures->roi();

                return [
                    'name' => $version->label().' · '.$version->flow->name,
                    'sub' => 'Published '.$version->published_at->format('j M Y'),
                    'ahead' => $ahead === (int) $id,
                    'share' => $shares->has((int) $id) ? $shares[(int) $id].'%' : '—',
                    'shareNote' => $shares->has((int) $id) ? 'of new visitors' : 'no share now',
                    'newVisitors' => number_format($figures->newVisitors()),
                    'clicks' => number_format($figures->visits).' '.str('click')->plural($figures->visits),
                    'finished' => self::percent($figures->completed, $figures->opened),
                    'finishedCount' => number_format($figures->completed),
                    'goal' => self::percent($figures->goal, $figures->opened),
                    'goalCount' => number_format($figures->goal),
                    'accounts' => number_format($figures->accounts),
                    'accountRate' => self::percent($figures->accounts, $figures->newVisitors()).' of new',
                    'costPerAccount' => $figures->costPerAccount() === null ? '—' : TrafficMoney::format($figures->costPerAccount()),
                    'spent' => TrafficMoney::format($figures->spend).' spent',
                    'perVisitor' => self::perVisitor($figures->value(), $figures->opened),
                    'roi' => $roi === null ? '—' : ($roi > 0 ? '+' : '').number_format($roi, 0).'%',
                    'roiTone' => $roi === null || abs($roi) < 0.5 ? null : ($roi > 0 ? 'good' : 'bad'),
                ];
            })->values()->all(),
        ];
    }

    /**
     * Every visitor of the game by where they came from: the ad networks,
     * the banners and links and the unpaid ways in, each with its sources —
     * how many opened the game, started, finished and reached the goal, what
     * a visitor was worth and cost — and all of them together.
     *
     * @param  Collection<int, array{channel: string, source_id: int|null, version_id: int|null, device: string, first_at: CarbonImmutable, figures: ReportFigures}>  $traffic
     * @param  array<int, int>  $campaigns
     * @return array<string, mixed>|null
     */
    private static function sourceRows(Game $game, Collection $traffic, string $device, array $campaigns): ?array
    {
        if ($traffic->isEmpty()) {
            return null;
        }

        $rows = in_array($device, ['mobile', 'desktop'], true) ? $traffic->where('device', $device) : $traffic;
        $sources = TrafficSource::query()->whereIn('id', $rows->pluck('source_id')->filter()->unique())->get()->keyBy('id');
        $types = ['network' => 'Ad networks', 'placement' => 'Banners & links', 'unpaid' => 'Unpaid'];
        $channels = [
            'internal' => ['Inside RateGuru', 'The feed, a post page or another game'],
            'shared' => ['Shared results', 'Opened from a shared result'],
            'referral' => ['Other sites', 'A link on another site'],
            'search' => ['Search', 'A search engine'],
            'direct' => ['Direct', 'No referrer'],
        ];
        $report = fn (array $filters): string => TrafficSourcesPage::getUrl(array_filter(['game' => $game->id, 'device' => $device !== 'all' ? $device : null, ...$filters]));
        $groups = [];

        foreach ($types as $type => $label) {
            $ofType = $rows->filter(fn (array $row): bool => ($type === 'unpaid' ? $row['source_id'] === null : $row['channel'] === $type && $row['source_id'] !== null));

            if ($ofType->isEmpty()) {
                continue;
            }

            $children = $type === 'unpaid'
                ? $ofType->groupBy('channel')->map(fn (Collection $group, string $channel): array => self::sourceRow($channels[$channel][0] ?? ucfirst($channel), $channels[$channel][1] ?? '', self::sum($group), false, $report(['type' => 'unpaid'])))
                : $ofType->groupBy('source_id')->map(fn (Collection $group, int|string $id): array => self::sourceRow(
                    $sources[(int) $id]->name ?? 'Source #'.$id,
                    $type === 'network' ? number_format($campaigns[(int) $id] ?? 0).' '.str('campaign')->plural($campaigns[(int) $id] ?? 0) : ($sources[(int) $id]->place_site ?? 'A banner or link'),
                    self::sum($group),
                    true,
                    $report(['source' => (int) $id]),
                ));
            $groups[] = [
                ...self::sourceRow($label, $children->count().' '.str('source')->plural($children->count()), self::sum($ofType), $type !== 'unpaid', $report(['type' => $type])),
                'children' => $children->sortByDesc('opened')->values()->all(),
            ];
        }

        $all = self::sum($rows);
        $paid = self::sum($rows->where('source_id', '!==', null));
        $roi = $paid->roi();

        return [
            'note' => 'Every visitor of '.$game->title.' by where they came from. A visitor is a visit that opened the page. Click a row to open it in Traffic sources.',
            'reportUrl' => $report([]),
            'groups' => $groups,
            'total' => [
                ...self::sourceRow('All visitors', 'Every source', $all, false, $report([])),
                'cost' => $paid->opened > 0 ? self::perVisitor(TrafficMoney::of($paid->spend), $paid->opened) : '—',
                'costNote' => 'paid visitors',
                'roi' => $roi === null ? '—' : ($roi > 0 ? '+' : '').number_format($roi, 0).'%',
                'roiNote' => 'paid only',
                'roiTone' => $roi === null || abs($roi) < 0.5 ? null : ($roi > 0 ? 'good' : 'bad'),
            ],
        ];
    }

    /** @return array<string, mixed> one row of the Traffic sources card */
    private static function sourceRow(string $name, string $sub, ReportFigures $figures, bool $paid, string $url): array
    {
        $roi = $paid ? $figures->roi() : null;

        return [
            'name' => $name,
            'sub' => $sub,
            'url' => $url,
            'opened' => $figures->opened,
            'cells' => [
                [number_format($figures->opened), number_format($figures->visits).' '.str('visit')->plural($figures->visits)],
                [number_format($figures->started), self::percent($figures->started, $figures->opened)],
                [number_format($figures->completed), self::percent($figures->completed, $figures->started)],
                [number_format($figures->goal), self::percent($figures->goal, $figures->completed)],
            ],
            'value' => self::perVisitor($figures->value(), $figures->opened),
            'cost' => $paid ? self::perVisitor(TrafficMoney::of($figures->spend), $figures->opened) : '—',
            'costNote' => $paid ? null : 'unpaid',
            'roi' => $paid ? ($roi === null ? '—' : ($roi > 0 ? '+' : '').number_format($roi, 0).'%') : '—',
            'roiNote' => null,
            'roiTone' => $roi === null || abs($roi) < 0.5 ? null : ($roi > 0 ? 'good' : 'bad'),
        ];
    }

    /**
     * @template TKey of array-key
     * @template TRow of array{figures: ReportFigures}
     *
     * @param  Collection<TKey, TRow>  $rows
     */
    private static function sum(Collection $rows): ReportFigures
    {
        return $rows->reduce(fn (ReportFigures $total, array $row): ReportFigures => $total->plus($row['figures']), new ReportFigures);
    }

    private static function perVisitor(BigDecimal $amount, int $visitors): string
    {
        return $visitors === 0 ? '—' : TrafficMoney::fine($amount->dividedBy($visitors, 8, RoundingMode::HalfUp));
    }

    private static function dates(CarbonImmutable $from, CarbonImmutable $until): string
    {
        return $from->format('d M').' — '.$until->subSecond()->format('d M Y');
    }

    /**
     * How a figure moved against the window before: up or down by how much,
     * or that the window before had none.
     *
     * @return array{text: string, tone: string|null}|null
     */
    private static function change(float $now, ?float $before): ?array
    {
        if ($before === null) {
            return null;
        }

        if ($before <= 0.0) {
            return ['text' => $now > 0.0 ? 'No earlier data' : '', 'tone' => null];
        }

        $moved = ($now - $before) * 100 / $before;

        return abs($moved) < 0.5
            ? ['text' => 'Same as previous period', 'tone' => null]
            : ['text' => ($moved > 0 ? '▲ ' : '▼ ').number_format(abs($moved), 0).'% vs previous period', 'tone' => $moved > 0 ? 'good' : 'bad'];
    }

    /**
     * The funnel's rows, each with how its count moved against the same row
     * of the window before.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>|null  $before
     * @return list<array<string, mixed>>
     */
    private static function withChanges(array $rows, ?array $before): array
    {
        $earlier = collect($before ?? [])->keyBy('label');

        return array_map(fn (array $row): array => [
            ...$row,
            'change' => $before === null ? null : self::change((float) $row['people'], isset($earlier[$row['label']]) ? (float) $earlier[$row['label']]['people'] : 0.0),
        ], $rows);
    }

    private static function windowStart(string $period, CarbonImmutable $now): CarbonImmutable
    {
        return match ($period) {
            '7d' => $now->subDays(7),
            '90d' => $now->subDays(90),
            'all' => CarbonImmutable::createFromTimestampUTC(0),
            default => $now->subDays(30),
        };
    }

    /** Whether an anonymous visitor of the version can meet an Email capture step. */
    private static function asksForEmail(GameVersion $version): bool
    {
        foreach ($version->read()->steps as $step) {
            if ($step->type === GameFlowStepType::EmailCapture && $step->audience !== GameStepAudience::Authenticated) {
                return true;
            }
        }

        return false;
    }

    /**
     * The version with the most attempts started in the window, the newest
     * first among equals; the newest version when nobody started any.
     *
     * @param  Collection<int, GameVersion>  $versions
     * @param  array<int, GameFunnel>  $byVersion
     */
    private static function busiest(Collection $versions, array $byVersion): ?GameVersion
    {
        return $versions->sortByDesc(fn (GameVersion $version): array => [$byVersion[(int) $version->id]->started ?? 0, (int) $version->number])->first();
    }

    /**
     * People at every step, as the funnel counts them, and what the window's
     * visits were worth, each against the window before.
     *
     * @return list<array{label: string, value: string, note: string, change: array{text: string, tone: string|null}|null}>
     */
    private static function anonymousKpis(GameFunnel $total, ?GameFunnel $before, ReportFigures $worth, ?ReportFigures $worthBefore, string $goal): array
    {
        return [
            ['label' => 'Visitors', 'value' => number_format($total->visitors), 'note' => 'People who opened the game', 'change' => self::change($total->visitors, $before?->visitors)],
            ['label' => 'Game started', 'value' => number_format($total->starters), 'note' => self::percent($total->starters, $total->visitors).' of visitors', 'change' => self::change($total->starters, $before?->starters)],
            ['label' => 'Completed', 'value' => number_format($total->finishers), 'note' => self::percent($total->finishers, $total->starters).' of starters', 'change' => self::change($total->finishers, $before?->finishers)],
            ['label' => 'Goal reached', 'value' => number_format($total->goalPeople), 'note' => self::percent($total->goalPeople, $total->finishers).' of completed · '.$goal, 'change' => self::change($total->goalPeople, $before?->goalPeople)],
            [
                'label' => 'Value',
                'value' => $worth->visits === 0 ? '—' : TrafficMoney::format($worth->value()),
                'note' => $worth->visits === 0 ? 'No visits recorded in Traffic sources' : self::perVisitor($worth->value(), $worth->opened).' per visitor · accounts at what one brings',
                'change' => $worth->visits === 0 ? null : self::change((float) (string) $worth->value(), $worthBefore === null ? null : (float) (string) $worthBefore->value()),
            ],
        ];
    }

    /** @return list<array{label: string, value: string, note: string, change: array{text: string, tone: string|null}|null}> */
    private static function playerKpis(GameFunnel $total, ?GameFunnel $before): array
    {
        return [
            ['label' => 'Players', 'value' => number_format($total->visitors), 'note' => 'Logged-in players who opened the game', 'change' => self::change($total->visitors, $before?->visitors)],
            ['label' => 'Game started', 'value' => number_format($total->starters), 'note' => self::percent($total->starters, $total->visitors).' of players', 'change' => self::change($total->starters, $before?->starters)],
            ['label' => 'Completed', 'value' => number_format($total->finishers), 'note' => self::percent($total->finishers, $total->starters).' of starters · '.self::plays($total), 'change' => self::change($total->finishers, $before?->finishers)],
            ['label' => 'Shared', 'value' => self::percent($total->shared, $total->completed), 'note' => number_format($total->shared).' '.str('result')->plural($total->shared).' shared', 'change' => self::change($total->shared, $before?->shared)],
            ['label' => 'Played again', 'value' => number_format($total->replayers), 'note' => 'Players who started more than once', 'change' => self::change($total->replayers, $before?->replayers)],
        ];
    }

    /** How many plays were completed, of how many, a replay one more. */
    private static function plays(GameFunnel $total): string
    {
        return number_format($total->completed).' of '.number_format($total->started).' '.str('play')->plural($total->started).' completed';
    }

    /**
     * The anonymous funnel, people at every step: Email captured only where
     * a version asks for one, then who began signing up — by how — and the
     * accounts made, both shares of the people who finished rather than of
     * the step above.
     *
     * @param  array<string, int>  $methods
     * @return list<array<string, mixed>>
     */
    private static function anonymousFunnel(GameFunnel $total, int $finishedWithEmail, bool $asksForEmail, array $methods): array
    {
        return self::funnelRows($total->visitors, array_values(array_filter([
            ['Visitors', $total->visitors, null, null, 'People who opened the game'],
            ['Game started', $total->starters, $total->visitors, 'Visitors', 'People who started at least once'],
            ['Completed', $total->finishers, $total->starters, 'Game started', 'People who finished at least once'],
            $asksForEmail ? ['Email captured', $total->emailers, $finishedWithEmail, 'finishers asked', 'Of the people who finished a version that asks for an email'] : null,
            ['Sign-up begun', $total->signUpStarters, $total->finishers, 'Completed', self::methods($methods)],
            ['Goal reached', $total->goalPeople, $total->starters, 'Game started', 'People who reached their flow’s goal at least once, on any page of the site'],
            ['Account confirmed', $total->registered, $total->finishers, 'Completed', 'Signed up within 7 days of finishing, on any page of the site, and confirmed'],
        ])));
    }

    /**
     * How the people who began signing up began it.
     *
     * @param  array<string, int>  $methods
     */
    private static function methods(array $methods): string
    {
        $labels = ['email' => 'Email', 'google' => 'Google', 'facebook' => 'Facebook', 'register' => 'Sign-up dialog', 'login' => 'Log in'];
        $parts = [];

        foreach ($labels as $method => $label) {
            if (($methods[$method] ?? 0) > 0) {
                $parts[] = $label.' '.number_format($methods[$method]);
            }
        }

        return $parts === [] ? 'The email form opened, a provider chosen, or the site\'s sign-up or log-in' : implode(' · ', $parts);
    }

    /**
     * The logged-in funnel: who played again is of the players who started,
     * since a replay needs no share before it.
     *
     * @return list<array<string, mixed>>
     */
    private static function playerFunnel(GameFunnel $total): array
    {
        return self::funnelRows($total->visitors, [
            ['Players', $total->visitors, null, null, 'Logged-in players who opened the game'],
            ['Game started', $total->starters, $total->visitors, 'Players', 'Players who started at least once'],
            ['Completed', $total->finishers, $total->starters, 'Game started', 'Players who finished at least once'],
            ['Shared result', $total->sharers, $total->finishers, 'Completed', null],
            ['Played again', $total->replayers, $total->starters, 'Game started', 'Players who started more than once'],
        ]);
    }

    /**
     * Each step with its rate, a share of the step it is measured against,
     * named — not always the step above it.
     *
     * @param  list<array{0: string, 1: int, 2: int|null, 3: string|null, 4: string|null}>  $steps
     * @return list<array<string, mixed>>
     */
    private static function funnelRows(int $start, array $steps): array
    {
        return array_map(fn (array $step): array => [
            'label' => $step[0],
            'hint' => $step[4],
            'people' => $step[1],
            'count' => number_format($step[1]),
            'bar' => $start > 0 ? min(100, round($step[1] * 100 / $start, 1)) : 0,
            'rate' => $step[2] === null ? '—' : self::percent($step[1], $step[2]),
            'rateOf' => $step[3] === null ? null : 'of '.$step[3],
            'fromStart' => self::percent($step[1], $start),
        ], $steps);
    }

    /**
     * The rounds and results of the version the figures look into: the one
     * chosen, or the busiest of the window.
     *
     * @return array<string, mixed>
     */
    private static function focus(GameVersion $version, GameMetricsQuery $metrics, GameMetricsScope $scope, GameFunnel $funnel, bool $chosenForYou): array
    {
        $snapshot = $version->read();
        $reach = $metrics->reach($scope, (int) $version->id);
        $results = $metrics->results($scope, (int) $version->id);
        $rounds = [];
        $previous = $funnel->started;

        foreach ($snapshot->rounds as $index => $round) {
            $answered = $reach[$index] ?? 0;
            $rounds[] = [
                'label' => 'Round '.($index + 1),
                'question' => $round->question->english(),
                'unscored' => ! $round->scored,
                'bar' => $funnel->started > 0 ? min(100, round($answered * 100 / $funnel->started, 1)) : 0,
                'reached' => self::percent($answered, $funnel->started),
                'count' => number_format($answered),
                'lost' => $index > 0 && $previous > $answered ? number_format($previous - $answered).' left' : null,
            ];
            $previous = $answered;
        }

        $completed = array_sum($results);

        return [
            'label' => $version->label(),
            'note' => ($chosenForYou ? "{$version->label()} has the most attempts here; choose a version above to see another. " : '')
                .'Of the '.number_format($funnel->started).' '.str('attempt')->plural($funnel->started).' started, how many answered each round.',
            'rounds' => $rounds,
            'resultsNote' => number_format($completed).' completed '.str('attempt')->plural($completed).', by the result each earned.',
            'results' => array_map(fn ($profile): array => [
                'label' => $profile->name->english(),
                'sub' => $profile->min === $profile->max ? "{$profile->min} ".str('match')->plural($profile->min) : "{$profile->min}–{$profile->max} matches",
                'count' => number_format($results[$profile->key] ?? 0),
                'bar' => $completed > 0 ? round(($results[$profile->key] ?? 0) * 100 / $completed, 1) : 0,
                'share' => self::percent($results[$profile->key] ?? 0, $completed),
            ], $snapshot->profiles),
        ];
    }

    /**
     * @param  array<int, GameFunnel>  $funnels
     * @param  Collection<int, GameVersion>  $versions
     * @return array{head: list<string>, rows: list<array<string, mixed>>}
     */
    private static function breakdownRows(string $by, array $funnels, Collection $versions, GameAudience $audience): array
    {
        $head = $audience === GameAudience::Anonymous
            ? [self::BREAKDOWNS[$by], 'Visitors', 'Started', 'Completed', 'Goal', 'Emails', 'Accounts']
            : [self::BREAKDOWNS[$by], 'Players', 'Started', 'Completed', 'Goal', 'Shared', 'Played again'];
        $rows = [];

        foreach ($funnels as $key => $funnel) {
            $named = match ($by) {
                'flow' => $versions->filter(fn (GameVersion $version): bool => (int) $version->flow_id === $key),
                'set' => $versions->filter(fn (GameVersion $version): bool => (int) $version->content_set_id === $key),
                default => $versions->only([$key]),
            };
            $first = $named->first();

            if ($first === null) {
                continue;
            }

            $rows[] = [
                'name' => match ($by) {
                    'flow' => $first->flow->name,
                    'set' => $first->contentSet->name,
                    default => $first->label(),
                },
                'sub' => $by === 'version' ? $first->contentSet->name.' · '.$first->flow->name : 'Used by '.$named->map(fn (GameVersion $version): string => $version->label())->sortBy(fn (string $label): int => (int) substr($label, 1))->implode(', '),
                'cells' => $audience === GameAudience::Anonymous
                    ? [
                        [number_format($funnel->visitors), null],
                        [number_format($funnel->starters), self::percent($funnel->starters, $funnel->visitors)],
                        [number_format($funnel->finishers), self::percent($funnel->finishers, $funnel->starters)],
                        [number_format($funnel->goalPeople), self::percent($funnel->goalPeople, $funnel->finishers)],
                        [number_format($funnel->emailers), self::percent($funnel->emailers, $funnel->finishers)],
                        [number_format($funnel->registered), self::percent($funnel->registered, $funnel->visitors)],
                    ]
                    : [
                        [number_format($funnel->visitors), null],
                        [number_format($funnel->starters), self::percent($funnel->starters, $funnel->visitors)],
                        [number_format($funnel->finishers), self::percent($funnel->finishers, $funnel->starters)],
                        [number_format($funnel->goalPeople), self::percent($funnel->goalPeople, $funnel->finishers)],
                        [number_format($funnel->sharers), self::percent($funnel->sharers, $funnel->finishers)],
                        [number_format($funnel->replayers), self::percent($funnel->replayers, $funnel->starters)],
                    ],
            ];
        }

        return ['head' => $head, 'rows' => $rows];
    }

    /**
     * The audience's traffic periods, newest first, with what was assigned,
     * started and completed in each.
     *
     * @param  array<int, array{started: int, completed: int}>  $attempts
     * @return list<array<string, mixed>>
     */
    private static function periodRows(Game $game, GameAudience $audience, array $attempts): array
    {
        return GameTrafficPeriod::query()
            ->where('game_id', $game->id)
            ->where('audience', $audience->value)
            ->with('versions')
            ->withCount('assignments')
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (GameTrafficPeriod $period): array => [
                'dates' => $period->started_at->format('d M Y').' — '.($period->ended_at?->format('d M Y') ?? 'now'),
                'shares' => $period->versions->map(fn (GameVersion $version): string => $version->label().' '.((int) $version->getRelation('pivot')->getAttribute('share_percent')).'%')->implode(' · ') ?: 'No traffic',
                'figures' => number_format((int) $period->assignments_count).' assigned · '
                    .number_format($attempts[(int) $period->id]['started'] ?? 0).' started · '
                    .number_format($attempts[(int) $period->id]['completed'] ?? 0).' completed',
                'current' => $period->ended_at === null,
            ])
            ->all();
    }

    private static function percent(int $part, int $whole): string
    {
        $rate = GameFunnel::rate($part, $whole);

        return $rate === null ? '—' : number_format($rate, 1).'%';
    }
}
