<?php

namespace App\Filament\Resources\Games\Analytics;

use App\Enums\GameAudience;
use App\Enums\TrafficDevice;
use App\Filament\Pages\Experiments\ExperimentsView;
use App\Filament\Pages\ExperimentsPage;
use App\Filament\Pages\TrafficSourcesPage;
use App\Filament\Resources\Games\GameResource;
use App\Models\Game;
use App\Models\GameTrafficPeriod;
use App\Models\GameVersion;
use App\Models\TrafficPriceSet;
use App\Models\TrafficSetting;
use App\Models\TrafficSource;
use App\Queries\Games\GameMetricsQuery;
use App\Queries\Games\TrafficPeriodScopesQuery;
use App\Queries\Traffic\TrafficReportQuery;
use App\Queries\Users\UnconfirmedAccountsQuery;
use App\Support\Games\Analytics\GameFunnel;
use App\Support\Games\Analytics\GameMetricsScope;
use App\Support\Geo\Countries;
use App\Support\Traffic\Money\TrafficMoney;
use App\Support\Traffic\Reports\ReportFigures;
use App\Support\Traffic\Reports\SignupFigures;
use App\Support\Traffic\Reports\TwoProportionTest;
use App\Support\Traffic\Reports\VisitFilter;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A game's analytics, as its own Analytics tab (GameAnalytics) and
 * Growth → Analytics → Games both draw it: how far its visitors got —
 * anonymous visitors and logged-in players apart — over a window, for every
 * version or one, from every device and country or one, against the window
 * of the same length before it: the figures and what the visits were worth,
 * the versions on paid traffic compared, the visitors by where they came
 * from, the funnel, who signed up and whether they confirmed, how many
 * attempts reached each round, which results players earned, the versions,
 * flows, content sets, devices, countries or country groups side by side,
 * and the traffic periods the figures were made in. Each page brings its own
 * window (GameAnalyticsWindow) and device, and the game's own filters
 * (ReadsGameAnalytics) as their address keeps them; whatever they hold that
 * can't be read is read as none.
 *
 * Playing figures are read from what playing recorded (GameMetricsQuery);
 * worth, spend, where visitors came from and the sign-ups from Traffic
 * sources (TrafficReportQuery), since an account that never confirmed is
 * removed with everything it did, and the game's own figures with it. The
 * game's tables know no device or country: with one chosen, both read the
 * recorded visits, and a play that came on none drops out (VisitFilter).
 * Reads only.
 */
final class GameAnalyticsView
{
    public const array BREAKDOWNS = ['version' => 'Version', 'flow' => 'Flow', 'set' => 'Content set', 'device' => 'Device', 'country' => 'Country', 'group' => 'Group'];

    /** What a country chosen as a group is named by in the address: `group:` and the group's key. */
    private const string GROUP_PREFIX = 'group:';

    public function __construct(
        private readonly GameMetricsQuery $metrics,
        private readonly TrafficReportQuery $reports,
    ) {}

    /**
     * Everything a game's analytics draws below its header, for the window,
     * the device and the game's own filters given.
     *
     * @param  string  $audience  a GameAudience
     * @param  mixed  $version  every version (0), or one of the game's by id
     * @param  mixed  $country  every country, a country's code, or a group of the price set as `group:` and its key
     * @param  string  $breakdown  one of BREAKDOWNS
     * @param  mixed  $paidSource  Versions on paid traffic: every paid source (0), or one by id
     * @param  string  $sourcesDevice  Traffic sources' own device while the page is of every device: all, mobile or desktop
     * @return array<string, mixed>
     */
    public function read(Game $game, GameAnalyticsWindow $window, ?TrafficDevice $device, string $audience, mixed $version, mixed $country, string $breakdown, mixed $paidSource, string $sourcesDevice): array
    {
        $audience = GameAudience::tryFrom($audience) ?? GameAudience::Anonymous;
        $breakdown = array_key_exists($breakdown, self::BREAKDOWNS) ? $breakdown : 'version';
        /** @var Collection<int, GameVersion> $versions */
        $versions = GameVersion::query()->where('game_id', $game->id)->with(['contentSet', 'flow'])->orderByDesc('number')->get()->keyBy('id');
        $versionId = is_numeric($version) && $versions->has((int) $version) ? (int) $version : null;
        /** @var list<array{key: string, name: string, percent: int, countries: list<string>}> $groups */
        $groups = TrafficPriceSet::current()->country_groups;
        [$country, $visits] = self::visitFilter($device, $country, $groups);
        $countryGroups = array_map(fn (array $group): array => $group['countries'], array_slice($groups, 0, -1));
        [$from, $until, $before] = [$window->from, $window->until, $window->before];
        $scope = new GameMetricsScope((int) $game->id, $audience, $from, $until, $versionId, $visits);
        $metrics = $this->metrics;
        $reports = $this->reports;

        $total = $metrics->funnels($scope)[0];
        $earlier = $before !== null ? $metrics->funnels($scope->with(from: $before, to: $from))[0] : null;
        $byVersion = $metrics->funnels($scope, 'version');
        $ofVersion = fn (array $row): bool => $versionId === null || $row['version_id'] === $versionId;
        $traffic = collect($reports->gameBreakdown((int) $game->id, $from, $until, $visits))->filter($ofVersion)->values();
        $worth = self::sum($traffic);
        $worthBefore = $before !== null ? self::sum(collect($reports->gameBreakdown((int) $game->id, $before, $from, $visits))->filter($ofVersion)) : null;
        $goals = ($versionId !== null ? $versions->only([$versionId]) : $versions)->map(fn (GameVersion $version): string => $version->flowGoal()->label())->unique()->values();
        // Of the versions the figures are of: the one chosen, or every one.
        $ofScope = $versionId !== null ? $versions->only([$versionId]) : $versions;
        $emailVersions = $ofScope->filter(fn (GameVersion $version): bool => $version->asksAnonymousForEmail())->keys()->all();
        // Each person once, however many of those versions they finished.
        $finishedWithEmail = count($emailVersions) === $ofScope->count() ? $total->finishers : $metrics->finishers($scope, $emailVersions);
        $focus = $versionId !== null ? $versions->get($versionId) : self::busiest($versions, $byVersion);
        $other = $audience === GameAudience::Anonymous ? GameAudience::Authenticated : GameAudience::Anonymous;
        $visitorCounts = [$audience->value => $total->visitors, $other->value => $metrics->visitors($scope->with(audience: $other))];
        // Traffic sources follows the page's device; its own switch narrows it only while the page is of every device.
        $sourcesDevice = $device !== null ? $device->value : (in_array($sourcesDevice, ['mobile', 'desktop'], true) ? $sourcesDevice : 'all');
        // The links to Traffic sources carry the period, and the device each card is of.
        $paidLinks = [...$window->links, 'device' => $device?->value];
        $sourceLinks = [...$window->links, 'device' => $sourcesDevice !== 'all' ? $sourcesDevice : null];

        return [
            'audience' => $audience->value,
            'anonymous' => $audience === GameAudience::Anonymous,
            'audiences' => ['anonymous' => 'Anonymous visitors', 'authenticated' => 'Logged-in players'],
            'audienceCounts' => array_map(number_format(...), $visitorCounts),
            'versionOptions' => [
                ['value' => '0', 'label' => 'All versions', 'trigger' => 'All'],
                ...$versions->map(fn (GameVersion $version): array => [
                    'value' => (string) $version->id,
                    'label' => $version->label().' · '.$version->contentSet->name.' · '.$version->flow->name,
                    'trigger' => $version->label(),
                ])->values()->all(),
            ],
            'device' => $device === null ? '' : $device->value,
            'deviceOptions' => [['value' => '', 'label' => 'All devices', 'trigger' => 'All'], ...array_map(fn (TrafficDevice $each): array => ['value' => $each->value, 'label' => $each->label()], TrafficDevice::cases())],
            'country' => $country,
            'countryOptions' => self::countryOptions($groups, $reports->gameCountries((int) $game->id), $country),
            'visitNote' => $visits->narrows() ? 'Only plays that came on a visit Traffic sources recorded are counted while a device or country is chosen.' : null,
            'breakdown' => $breakdown,
            'breakdowns' => self::BREAKDOWNS,
            'breakdownNote' => in_array($breakdown, ['device', 'country', 'group'], true)
                ? 'Each play by the recorded visit it came on, each visitor by their visits of the game: plays without one are left out, and a visitor who came from two places counts in each.'
                : 'Versions are a fair comparison only inside one traffic period; a person who saw two versions counts in each.',
            'datesText' => $window->text(),
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
            'breakdownRows' => self::breakdownRows($breakdown, $breakdown === 'version' ? $byVersion : $metrics->funnels($scope, $breakdown, $countryGroups), $versions, $audience, $groups),
            'signups' => $audience === GameAudience::Anonymous
                ? self::signups($reports->gameSignups((int) $game->id, $from, $until, $versionId, UnconfirmedAccountsQuery::cutoff(), $visits), (bool) TrafficSetting::current()->capture_enabled, $versionId !== null)
                : null,
            'periodRows' => self::periodRows($game, $audience, $metrics, $versionId !== null ? $versions->get($versionId) : null),
            'periodVersion' => $versionId !== null ? $versions->get($versionId)?->label() : null,
            'periodsNote' => self::periodsNote($audience, $versionId !== null ? $versions->get($versionId)?->label() : null),
            'trafficUrl' => GameResource::getUrl('traffic', ['record' => $game]),
            'versionsUrl' => GameResource::getUrl('versions', ['record' => $game]),
            'paid' => self::paidVersions($game, $traffic, $versions, is_numeric($paidSource) ? max(0, (int) $paidSource) : 0, $window->name, $paidLinks),
            'sources' => self::sourceRows($game, $traffic, $sourcesDevice, $reports->gameCampaigns((int) $game->id, $from, $until, $visits), $sourceLinks),
            // The card's own switch only while the page is of every device.
            'sourcesSwitch' => $device === null,
            'cardDevice' => $sourcesDevice,
            'window' => $window->name,
            'gameId' => (int) $game->id,
            'gameTitle' => $game->title,
        ];
    }

    /**
     * The country as the address keeps it: a code the country list knows, a
     * group of the price set as `group:` and its key, or every country ('')
     * for anything else.
     *
     * @param  list<array{key: string, name: string, percent: int, countries: list<string>}>  $groups
     */
    public static function country(mixed $country, array $groups): string
    {
        return self::visitFilter(null, $country, $groups)[0];
    }

    /**
     * The device and country chosen, as the visits they admit, with the
     * country as the address keeps it: a code the country list knows, a
     * group of the price set as `group:` and its key — the last group every
     * country the others do not name — or every country for anything else.
     *
     * @param  list<array{key: string, name: string, percent: int, countries: list<string>}>  $groups
     * @return array{0: string, 1: VisitFilter}
     */
    private static function visitFilter(?TrafficDevice $device, mixed $country, array $groups): array
    {
        $country = is_string($country) ? $country : '';

        if (str_starts_with($country, self::GROUP_PREFIX)) {
            $key = substr($country, strlen(self::GROUP_PREFIX));
            $index = array_search($key, array_column($groups, 'key'), true);

            if ($index === false) {
                return ['', new VisitFilter($device)];
            }

            return $index === array_key_last($groups)
                ? [$country, new VisitFilter($device, array_merge([], ...array_column(array_slice($groups, 0, -1), 'countries')), true)]
                : [$country, new VisitFilter($device, $groups[$index]['countries'])];
        }

        return preg_match('/^[A-Z]{2}$/', $country) === 1 && Countries::has($country)
            ? [$country, new VisitFilter($device, [$country])]
            : ['', new VisitFilter($device)];
    }

    /**
     * Every country, then the price set's groups, then the countries the
     * game's recorded visits came from — the one chosen among them always.
     *
     * @param  list<array{key: string, name: string, percent: int, countries: list<string>}>  $groups
     * @param  list<string>  $visited
     * @return list<array{value: string, label: string, trigger?: string, count?: string}>
     */
    private static function countryOptions(array $groups, array $visited, string $chosen): array
    {
        $codes = $chosen !== '' && ! str_starts_with($chosen, self::GROUP_PREFIX) && ! in_array($chosen, $visited, true) ? [...$visited, $chosen] : $visited;
        usort($codes, fn (string $a, string $b): int => strcmp(Countries::name($a), Countries::name($b)));

        return [
            ['value' => '', 'label' => 'All countries', 'trigger' => 'All'],
            ...array_map(fn (array $group): array => ['value' => self::GROUP_PREFIX.$group['key'], 'label' => $group['name'], 'count' => 'Group'], $groups),
            ...array_map(fn (string $code): array => ['value' => $code, 'label' => Countries::name($code), 'count' => $code], $codes),
        ];
    }

    /**
     * The versions on paid traffic, of every paid source or one: each
     * version's new visitors, how far they got, its accounts, what an account
     * cost and what a visitor was worth, with the share it has now; and the
     * two that bring the most accounts per new visitor compared — whether one
     * brings more, or it is too early to say (TwoProportionTest). A source
     * chosen that sent nobody in the window stays chosen, with no rows.
     *
     * @param  Collection<int, array{channel: string, source_id: int|null, version_id: int|null, device: string, first_at: CarbonImmutable, figures: ReportFigures}>  $traffic
     * @param  Collection<int, GameVersion>  $versions
     * @param  array<string, string|null>  $links  the period and device the links to Traffic sources carry
     * @return array<string, mixed>|null
     */
    private static function paidVersions(Game $game, Collection $traffic, Collection $versions, int $sourceId, string $window, array $links): ?array
    {
        $paid = $traffic->where('source_id', '!==', null);

        if ($paid->isEmpty()) {
            return null;
        }

        // The source chosen stays a choice though it sent nobody in this window, so the menu, the address and the card agree.
        $names = TrafficSource::query()->whereIn('id', $paid->pluck('source_id')->when($sourceId > 0, fn (Collection $ids) => $ids->push($sourceId))->unique()->values())->orderBy('name')->pluck('name', 'id');
        $sourceId = $names->has($sourceId) ? $sourceId : 0;
        $rows = $sourceId === 0 ? $paid : $paid->where('source_id', $sourceId);
        // The shares the chosen source's clicks get now: its own split's when it has one, else the general split's.
        $open = GameTrafficPeriod::query()->where('game_id', $game->id)->where('audience', GameAudience::Anonymous->value)->whereNull('ended_at')->whereNull('campaign_id')
            ->where(fn (Builder $query) => $query->whereNull('source_id')->when($sourceId !== 0, fn (Builder $either) => $either->orWhere('source_id', $sourceId)))
            ->with('versions')->orderByDesc('id')->get();
        $shares = ($open->first(fn (GameTrafficPeriod $period): bool => $sourceId !== 0 && $period->source_id === $sourceId) ?? $open->first(fn (GameTrafficPeriod $period): bool => $period->source_id === null))
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
            'sources' => [['value' => '0', 'label' => 'All paid sources', 'trigger' => 'All paid'], ...$names->map(fn (string $name, int $id): array => ['value' => (string) $id, 'label' => $name])->values()->all()],
            'source' => (string) $sourceId,
            'note' => 'New visitors from '.($sourceId === 0 ? 'ad networks and banners' : $names[$sourceId]).', '.$window.'. One campaign link for the game: RateGuru picks the version after the click, by the shares in Traffic.',
            'reportUrl' => TrafficSourcesPage::getUrl(array_filter(['game' => $game->id, 'view' => 'versions', 'source' => $sourceId ?: null, ...$links])),
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
     * @param  array<string, string|null>  $links  the period and device the links to Traffic sources carry
     * @return array<string, mixed>|null
     */
    private static function sourceRows(Game $game, Collection $traffic, string $device, array $campaigns, array $links): ?array
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
        $report = fn (array $filters): string => TrafficSourcesPage::getUrl(array_filter(['game' => $game->id, ...$links, ...$filters]));
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
     * Who signed up and whether they confirmed, counted from the visits of
     * the game — of the version chosen — that Traffic sources recorded in
     * the window: the funnel loses a sign-up that never confirmed once its
     * account is removed, so these stand by themselves, confirmed counted
     * from the same visits. With no visit recorded there is nothing to count
     * from: dashes, never a nought, and where visits are switched on.
     *
     * @param  array{visits: int, figures: SignupFigures}  $tracked
     * @return array{tracked: bool, figures: list<array{label: string, value: string, note: string}>, note: string, trafficUrl: string|null}
     */
    private static function signups(array $tracked, bool $recording, bool $ofVersion): array
    {
        $f = $tracked['figures'];
        $days = UnconfirmedAccountsQuery::GRACE_DAYS;
        $of = $ofVersion ? 'this version' : 'the game';

        if ($tracked['visits'] === 0) {
            return [
                'tracked' => false,
                'figures' => array_map(fn (string $label): array => ['label' => $label, 'value' => '—', 'note' => 'No visits recorded'], ['Signed up', 'Confirmed', 'Never confirmed', 'Still waiting']),
                'note' => $recording
                    ? 'No visit of '.$of.' was recorded in this period. Sign-ups are counted from the visits Traffic sources records while Record visits is on.'
                    : 'Visits aren’t recorded, so there are no sign-ups to count. Switch on Record visits under Traffic sources → Sources to count them here, those never confirmed included.',
                'trafficUrl' => ! $recording && TrafficSourcesPage::canAccess() ? TrafficSourcesPage::getUrl(['tab' => 'sources']) : null,
            ];
        }

        $off = $recording ? '' : ' Record visits is off now: visits since it was switched off, and their sign-ups, are not counted, so Still waiting can read 0.';

        return [
            'tracked' => true,
            'figures' => [
                ['label' => 'Signed up', 'value' => number_format($f->signedUp), 'note' => 'Of '.number_format($tracked['visits']).' recorded '.str('visit')->plural($tracked['visits'])],
                ['label' => 'Confirmed', 'value' => number_format($f->confirmed), 'note' => self::percent($f->confirmed, $f->signedUp).' of those who signed up'],
                ['label' => 'Never confirmed', 'value' => number_format($f->neverConfirmed), 'note' => 'Signed up over '.$days.' days ago · removed'],
                ['label' => 'Still waiting', 'value' => number_format($f->waiting), 'note' => 'Signed up in the last '.$days.' days'],
            ],
            'note' => 'Counted from the visits of '.$of.' Traffic sources recorded in this period: an account not confirmed within '.$days.' days is removed with everything it did, and the funnel loses it. Confirmed here are the sign-ups of these visits; the funnel’s Account confirmed counts accounts by the play they are credited to, so the two can differ.'.$off,
            'trafficUrl' => ! $recording && TrafficSourcesPage::canAccess() ? TrafficSourcesPage::getUrl(['tab' => 'sources']) : null,
        ];
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
     * The rows of Side by side: each version, flow or content set — named
     * from the versions made from it — or each device, country or country
     * group of the recorded visits, with its funnel.
     *
     * @param  array<int|string, GameFunnel>  $funnels
     * @param  Collection<int, GameVersion>  $versions
     * @param  list<array{key: string, name: string, percent: int, countries: list<string>}>  $groups
     * @return array{head: list<string>, rows: list<array<string, mixed>>}
     */
    private static function breakdownRows(string $by, array $funnels, Collection $versions, GameAudience $audience, array $groups): array
    {
        $head = $audience === GameAudience::Anonymous
            ? [self::BREAKDOWNS[$by], 'Visitors', 'Started', 'Completed', 'Goal', 'Emails', 'Accounts']
            : [self::BREAKDOWNS[$by], 'Players', 'Started', 'Completed', 'Goal', 'Shared', 'Played again'];
        $rows = [];

        foreach ($funnels as $key => $funnel) {
            $named = self::breakdownName($by, $key, $versions, $groups);

            if ($named === null) {
                continue;
            }

            $rows[] = [
                ...$named,
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
     * A row of Side by side named: a version with its set and flow; a flow or
     * set with the versions made from it — none when no version is; a device;
     * a country with its group; a group with its countries.
     *
     * @param  Collection<int, GameVersion>  $versions
     * @param  list<array{key: string, name: string, percent: int, countries: list<string>}>  $groups
     * @return array{name: string, sub: string|null}|null
     */
    private static function breakdownName(string $by, int|string $key, Collection $versions, array $groups): ?array
    {
        if ($by === 'device') {
            $device = TrafficDevice::tryFrom((string) $key);

            return ['name' => $device?->label() ?? ucfirst((string) $key), 'sub' => $device === TrafficDevice::Mobile ? 'Tablets count as mobile' : null];
        }

        if ($by === 'country') {
            $code = (string) $key;
            $group = $groups === [] ? null : collect($groups)->first(fn (array $group): bool => in_array($code, $group['countries'], true)) ?? $groups[array_key_last($groups)];

            return ['name' => $code === '' ? 'Unknown country' : Countries::name($code), 'sub' => trim(($code === '' ? '' : $code.' · ').($group['name'] ?? ''), ' ·')];
        }

        if ($by === 'group') {
            $group = $groups[(int) $key] ?? null;

            if ($group === null) {
                return null;
            }

            return ['name' => $group['name'], 'sub' => (int) $key === array_key_last($groups) ? 'Every country the other groups don’t name, and unknown ones' : implode(', ', $group['countries'])];
        }

        $named = match ($by) {
            'flow' => $versions->filter(fn (GameVersion $version): bool => (int) $version->flow_id === $key),
            'set' => $versions->filter(fn (GameVersion $version): bool => (int) $version->content_set_id === $key),
            default => $versions->only([$key]),
        };
        $first = $named->first();

        if ($first === null) {
            return null;
        }

        return [
            'name' => match ($by) {
                'flow' => $first->flow->name,
                'set' => $first->contentSet->name,
                default => $first->label(),
            },
            'sub' => $by === 'version' ? $first->contentSet->name.' · '.$first->flow->name : 'Used by '.$named->map(fn (GameVersion $version): string => $version->label())->sortBy(fn (string $label): int => (int) substr($label, 1))->implode(', '),
        ];
    }

    /**
     * The audience's traffic periods, newest first, with their scope (a
     * split's source and campaign, or “Everyone else” beside one), their shares, the
     * visitors sent to a version in each and what they started and
     * completed, and whether its versions can be compared yet — the badge
     * Growth → Experiments gives the period, from the same rule
     * (PeriodComparison::state()): one version has nothing to be compared
     * with; with more, enough data once each has the accounts the versions
     * comparison asks for (TwoProportionTest) — logged-in players make no
     * accounts, so their goals are counted instead. A period with shares
     * links to its results there. Of one version: only the periods it had a
     * share in, each still with every version's share, but with that
     * version's visitors and attempts alone.
     *
     * @return list<array<string, mixed>>
     */
    private static function periodRows(Game $game, GameAudience $audience, GameMetricsQuery $metrics, ?GameVersion $version): array
    {
        $periods = GameTrafficPeriod::query()
            ->where('game_id', $game->id)
            ->where('audience', $audience->value)
            ->when($version !== null, fn (Builder $query) => $query->whereHas('versions', fn (Builder $shared) => $shared->whereKey($version->id)))
            ->with('versions')
            ->withCount(['assignments' => fn (Builder $query) => $query->when($version !== null, fn (Builder $own) => $own->where('version_id', $version->id))])
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get();
        $attempts = $metrics->periods((int) $game->id, $version?->id);
        $funnels = $metrics->periodFunnel($periods->map(fn (GameTrafficPeriod $period): int => (int) $period->id)->all(), ExperimentsView::maturedBefore());
        $results = ExperimentsPage::canAccess();
        $scopes = app(TrafficPeriodScopesQuery::class)->labels($periods);

        return $periods
            ->map(function (GameTrafficPeriod $period) use ($attempts, $funnels, $audience, $version, $results, $scopes): array {
                $visitors = (int) $period->assignments_count;
                $comparison = ExperimentsView::comparison($period, $audience, $funnels[(int) $period->id] ?? []);
                $state = $comparison->versions === [] ? null : $comparison->state();

                return [
                    'dates' => $period->started_at->format('d M Y').' — '.($period->ended_at?->format('d M Y') ?? 'now'),
                    'scope' => $scopes[(int) $period->id] ?? null,
                    'shares' => $period->versions->map(fn (GameVersion $each): string => $each->label().' '.((int) $each->getRelation('pivot')->getAttribute('share_percent')).'%')->implode(' · ') ?: 'No traffic',
                    'figures' => ($version !== null ? $version->label().': ' : '')
                        .number_format($attempts[(int) $period->id]['started'] ?? 0).' started · '
                        .number_format($attempts[(int) $period->id]['completed'] ?? 0).' completed',
                    'visitors' => number_format($visitors).' '.str('visitor')->plural($visitors),
                    'badge' => $state === null ? null : ['label' => $state->label(), 'tone' => $state->tone()],
                    'current' => $period->ended_at === null,
                    'resultsUrl' => $state !== null && $results ? ExperimentsPage::getUrl(['period' => $period->id]) : null,
                ];
            })
            ->all();
    }

    /**
     * What Traffic periods shows, and when its versions can be compared: each
     * period read whole, as Growth → Experiments reads it, whatever the days,
     * device and country the rest of the page is narrowed to.
     */
    private static function periodsNote(GameAudience $audience, ?string $version): string
    {
        $enough = TwoProportionTest::MIN_ACCOUNTS.' '.($audience === GameAudience::Anonymous ? 'accounts' : 'players who reached the goal');

        return 'A new period starts whenever the shares change. Versions are only a fair comparison inside one period, and only once each has '.$enough.', as the versions comparison asks.'
            .($version !== null ? ' Periods '.$version.' had a share in, with every version’s share and '.$version.'’s figures only.' : '')
            .' Each period counts all of its own visitors, whatever the days, device and country chosen above.';
    }

    private static function percent(int $part, int $whole): string
    {
        $rate = GameFunnel::rate($part, $whole);

        return $rate === null ? '—' : number_format($rate, 1).'%';
    }
}
