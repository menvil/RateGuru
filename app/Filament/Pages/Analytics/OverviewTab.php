<?php

namespace App\Filament\Pages\Analytics;

use App\Enums\AnalyticsDimension;
use App\Enums\GeoEdition;
use App\Enums\TrafficConnectionStatus;
use App\Filament\Pages\GrowthAnalyticsPage;
use App\Filament\Pages\TrafficSourcesPage;
use App\Models\TrafficApiConnection;
use App\Models\TrafficSetting;
use App\Queries\Analytics\AnalyticsMarkersQuery;
use App\Queries\Analytics\AnalyticsVisitsQuery;
use App\Queries\Traffic\TrafficSpendQuery;
use App\Services\Geo\GeoAttribution;
use App\Support\Analytics\ActivityDay;
use App\Support\Analytics\AnalyticsFilter;
use App\Support\Analytics\AnalyticsPeriod;
use App\Support\Analytics\AnalyticsSource;
use App\Support\Analytics\CampaignKey;
use App\Support\Analytics\ChartScale;
use App\Support\Analytics\FigureChange;
use App\Support\Analytics\VisitorFigures;
use App\Support\Traffic\Money\TrafficMoney;

/**
 * Analytics → Overview, as the reference draws it: the people of the filter
 * against the window before — visitors, new visitors, game starters and
 * completers, accounts and activated users, and the rates of new visitors
 * becoming either — the acquisition funnel, whose accounts are the tile's,
 * with the way to the journey of one of its people on the Journeys tab
 * (GrowthAnalyticsPage::openExampleJourney()), and the visitors of each
 * hour, day or week with what happened on them.
 * Then, for the period and the device alone, whatever source and campaign
 * are chosen: the sources the most people came from, a click on one
 * filtering the whole page — while the page is narrowed, all of them still,
 * the source it is narrowed to lit among the rest, in the last place when
 * it is past them; what shared results
 * brought; why the figures can differ from an ad network's; and what
 * RateGuru records. Nobody matching the filter is said once, with nothing
 * else drawn.
 */
final class OverviewTab implements AnalyticsTab
{
    /** @var array<string, string> the badge tone of each group a source falls in */
    private const array GROUP_TONES = ['Paid' => 'outline', 'Unattributed' => 'warning'];

    /** A row's registration rate is flagged below this share of the table's own. */
    private const float DUE_SHARE = 2 / 3;

    public function __construct(
        private readonly AnalyticsVisitsQuery $visits,
        private readonly AnalyticsMarkersQuery $markers,
        private readonly AnalyticsView $view,
        private readonly GeoAttribution $geo,
        private readonly TrafficSpendQuery $spend,
    ) {}

    /** @return array<string, mixed> */
    public function data(GrowthAnalyticsPage $page, AnalyticsFilter $filter): array
    {
        $period = $filter->period;
        $figures = $this->visits->figures($filter);

        if ($figures->isEmpty()) {
            return ['empty' => true, 'range' => $period->range(), 'filtered' => $filter->device !== null || $filter->narrowsSource()];
        }

        $previousFilter = $filter->previous();
        $previous = $previousFilter === null ? null : $this->visits->figures($previousFilter);
        $base = $filter->periodAndDevice();
        // The source the page is narrowed to — the one chosen, or the one a paid campaign chosen came through — is listed,
        // taking the last place when it is past the sources the most people came from, so its row can be lit among the rest.
        $lit = $filter->sourceKey ?? $this->view->campaignSource($filter->campaignKey);
        $table = $this->visits->by($base, AnalyticsDimension::Source, (int) config('analytics.sources_top'), keep: $lit === null ? [] : [['source_key' => $lit]]);
        $all = $filter->narrowsSource() ? $this->visits->figures($base) : $figures;
        $quality = $this->visits->quality($base);

        // Spend no visit took — a fixed price on a day without clicks, a network's day before it is shared out — counts too,
        // as Traffic sources counts it, wherever no device narrows the visits.
        if ($base->device === null) {
            $quality['spend'] = array_reduce(
                $this->spend->unallocated($base->fromDay(), $base->toDay()),
                fn (string $total, array $row): string => TrafficMoney::stored(TrafficMoney::of($total)->plus(TrafficMoney::of($row['amount']))),
                $quality['spend'],
            );
        }
        $names = $this->view->sourceNames([...array_map(fn (array $row): int|string|null => $row['keys']['source_key'], $table['rows']), $filter->sourceKey]);
        $scope = $this->scope($filter, $names, $this->view->campaignNames([$filter->campaignKey], withSource: $filter->sourceKey === null));
        $sources = $this->sources($table, $all, $quality, $names, $lit, (string) $page->sort);

        return [
            'empty' => false,
            'kpis' => $this->kpis($figures, $previous, $period, $this->visits->attempts($filter)),
            'funnel' => ['note' => ($scope === '' ? '' : $scope.' · ').$period->label().'. Each step counts unique visitors, not attempts. Sign-ups, accounts and activations are shares of new visitors.', 'steps' => self::funnel($figures)],
            'chart' => $this->chart($filter, $scope),
            'sources' => $sources,
            // The table counts every source, and says so while a source or a campaign is chosen: no filter is ever silently ignored.
            'sourcesTitle' => $filter->narrowsSource() ? 'All traffic sources' : 'Traffic sources',
            'sourcesNote' => match (true) {
                $sources['lit'] => 'Every source for the period and device, the chosen one lit to compare with the rest. ',
                $filter->narrowsSource() => 'Every source for the period and device, whatever source or campaign is chosen. ',
                default => '',
            }.'Click a source to filter the whole page. Start rate uses visitors; registration and activation use new visitors.',
            'referral' => $this->referral($base, $table),
            'notes' => self::notes($quality),
            'tracking' => $this->tracking(),
        ];
    }

    /**
     * The eight tiles: the first six against the window before, the last
     * two the rates of new visitors becoming an account and an activated one.
     *
     * @return list<array{label: string, value: string, unit: string, change: string|null, flag: array{label: string, hint: string}|null}>
     */
    private function kpis(VisitorFigures $now, ?VisitorFigures $before, AnalyticsPeriod $period, int $attempts): array
    {
        $comparison = $before === null ? null : $period->comparison();
        $change = fn (int $value, ?int $earlier): ?string => FigureChange::between($value, $earlier, $comparison)?->text;
        $days = (int) config('analytics.activation_days');
        $matured = ActivityDay::maturedBefore($period->today, $days);

        // Accounts of the period's last days can still activate: their days to do so aren't over.
        $early = $period->toDay >= $matured->toDateString()
            ? ['label' => 'Too early', 'hint' => 'Accounts from '.$matured->format('d M').' on can still activate']
            : null;

        return [
            self::tile('Visitors', self::count($now->visitors), 'Browsers with a visit in the period', $change($now->visitors, $before?->visitors)),
            self::tile('New visitors', self::count($now->newVisitors), self::percent(VisitorFigures::rate($now->newVisitors, $now->visitors)).' of visitors', $change($now->newVisitors, $before?->newVisitors)),
            self::tile('Game starters', self::count($now->starters), self::count($attempts).($attempts === 1 ? ' attempt' : ' attempts').' in total', $change($now->starters, $before?->starters)),
            self::tile('Game completers', self::count($now->completers), self::percent($now->completion()).' of starters', $change($now->completers, $before?->completers)),
            self::tile('Accounts created', self::count($now->accounts), 'Confirmed new accounts', $change($now->accounts, $before?->accounts)),
            self::tile('Activated users', self::count($now->activated), 'Came back and did something within '.$days.' days', $change($now->activated, $before?->activated), $early),
            self::tile('Visitor → registration', self::percent($now->accountRate()), 'Accounts ÷ new visitors'),
            self::tile('Visitor → activation', self::percent($now->activationRate()), 'Activated ÷ new visitors'),
        ];
    }

    /**
     * The acquisition funnel: each step's people, its share of the step
     * before and of the visitors — of the new visitors from Signed up on,
     * since only they can become an account — and a bar against the visitors.
     *
     * @return list<array{name: string, hint: string, count: string, previous: string, first: string, bar: float, strong: bool}>
     */
    private static function funnel(VisitorFigures $figures): array
    {
        $steps = [
            ['Landing visitors', $figures->visitors, 'Browsers with a visit in the period'],
            ['Game starters', $figures->starters, 'Started a game'],
            ['Game completers', $figures->completers, 'Finished a game'],
            ['Reached the goal', $figures->goal, 'Reached the goal of the game’s flow'],
            ['Saw the sign-up form', $figures->signupForm, 'Were shown the sign-up form, after a game or anywhere on the site'],
            ['Signed up', $figures->signups, 'Signed up, confirmed or not yet'],
            ['Accounts confirmed', $figures->accounts, 'Confirmed a new account'],
            ['Activated users', $figures->activated, 'Came back and did something within '.config('analytics.activation_days').' days'],
        ];

        return array_map(fn (int $index, array $step): array => [
            'name' => $step[0],
            'hint' => $step[2],
            'count' => self::count($step[1]),
            // The sign-up form is shown on the site as well as after a game's goal, so its visitors are no share of the goal's.
            'previous' => $index === 0 || $index === 4 ? '—' : self::percent(VisitorFigures::rate($step[1], $steps[$index - 1][1])),
            'first' => self::percent(VisitorFigures::rate($step[1], $index >= 5 ? $figures->newVisitors : $figures->visitors)),
            'bar' => $figures->visitors === 0 ? 0.0 : round(min(1, $step[1] / $figures->visitors) * 100, 1),
            'strong' => $index >= 6,
        ], array_keys($steps), $steps);
    }

    /**
     * The visitors of each bucket — an hour of today still to come saying
     * so instead — each bucket marked dark when something happened on it,
     * with the legend of what did.
     *
     * A scale of round values says how many the bars stand for, the busiest
     * bucket is named, and pointing at a bar, tapping it or arrowing through
     * them reads its own count out — an hour still to come drawn apart from
     * an hour of nobody.
     *
     * @return array{title: string, note: string, bars: list<array{height: float, title: string, marked: bool, toCome: bool}>, scale: list<array{value: string, at: float}>, peak: string, ticks: list<string>, markers: list<string>, dense: bool}
     */
    private function chart(AnalyticsFilter $filter, string $scope): array
    {
        $period = $filter->period;
        $buckets = $period->buckets();
        $counts = $this->visits->series($filter, $buckets);
        $markers = array_reverse($this->markers->in($filter, (int) config('analytics.markers')));
        $marked = [];

        foreach ($markers as $marker) {
            foreach ($buckets as $index => $bucket) {
                $inside = $bucket['unit'] === AnalyticsPeriod::HOUR
                    ? $marker['at']->greaterThanOrEqualTo($bucket['from']) && $marker['at']->lessThan($bucket['before'])
                    : $marker['at']->toDateString() >= $bucket['fromDay'] && $marker['at']->toDateString() <= $bucket['toDay'];

                if ($inside) {
                    $marked[$index][] = $marker['text'];
                }
            }
        }

        $busiest = max(0, ...$counts);
        $scale = ChartScale::of($busiest);
        $peak = array_search($busiest, $counts, true);
        $unit = $period->unit();
        $each = match ($unit) {
            AnalyticsPeriod::HOUR => 'A visitor in two hours counts in each.',
            AnalyticsPeriod::WEEK => 'A visitor in two weeks counts in each.',
            default => 'A visitor on two days counts on each.',
        };
        $last = count($buckets) - 1;

        return [
            'title' => 'Visitors per '.$unit,
            'note' => $period->range().($scope === '' ? '' : ' · '.$scope).'. '.$each.' Point at or tap a bar, or arrow through them, for its exact count.',
            'bars' => array_map(fn (int $index, array $bucket): array => [
                'height' => $scale->height($counts[$index]),
                'title' => $bucket['hint'].' · '.($bucket['toCome'] ? 'still to come' : self::visitors($counts[$index])).(isset($marked[$index]) ? ' · '.implode(' · ', $marked[$index]) : ''),
                'marked' => isset($marked[$index]),
                'toCome' => (bool) $bucket['toCome'],
            ], array_keys($buckets), $buckets),
            'scale' => array_map(fn (int $line): array => ['value' => self::count($line), 'at' => $scale->height($line)], $scale->lines),
            'peak' => $busiest === 0 || $peak === false ? 'Nobody in these '.$unit.'s yet' : 'Busiest: '.$buckets[$peak]['hint'].' · '.self::visitors($busiest),
            'ticks' => array_values(array_unique([$buckets[0]['label'], $buckets[intdiv($last, 2)]['label'], $buckets[$last]['label']])),
            'markers' => array_map(fn (array $marker): string => $marker['at']->format($unit === AnalyticsPeriod::HOUR ? 'H:i' : 'd M').' · '.$marker['text'], $markers),
            'dense' => count($buckets) > 20,
        ];
    }

    /**
     * The sources table, of the period and the device alone: the sources the
     * most people came from, sorted as asked, each rate "—" below the
     * denominator a row needs; then everyone else's together, and the people
     * of them all, each once. The row of the source the page is narrowed to
     * is lit (`selected`), and the table says whether one is (`lit`).
     *
     * @param  array{rows: list<array{keys: array<string, int|string|null>, figures: VisitorFigures, visits: int, tagged: int}>, rest: array{figures: VisitorFigures, visits: int, tagged: int}|null}  $table
     * @param  array{visitors: int, direct: int, paidClicks: int, crawlers: int, neverRan: int, spend: string, spendEstimated: string}  $quality
     * @param  array<int, string>  $names
     * @return array<string, mixed>
     */
    private function sources(array $table, VisitorFigures $all, array $quality, array $names, ?string $chosen, string $sort): array
    {
        $minimum = (int) config('analytics.row_min_denominator');
        $tableRate = $all->accountRate();

        $row = function (?string $key, string $name, string $medium, string $group, string $basis, VisitorFigures $figures) use ($minimum, $tableRate, $chosen): array {
            $registration = $figures->accountRate($minimum);

            return [
                'key' => $key,
                'name' => $name,
                'medium' => $medium,
                'group' => $group,
                'tone' => self::GROUP_TONES[$group] ?? 'neutral',
                'basis' => $basis,
                'visitors' => self::count($figures->visitors),
                'cells' => [
                    [self::count($figures->starters), self::percent($figures->startRate($minimum))],
                    [self::count($figures->completers), self::percent($figures->completion($minimum))],
                    [self::count($figures->accounts), self::percent($registration)],
                    [self::count($figures->activated), self::percent($figures->activationRate($minimum))],
                ],
                'due' => $registration !== null && $tableRate !== null && $registration < $tableRate * self::DUE_SHARE,
                'selected' => $key !== null && $key === $chosen,
                'sort' => ['visitors' => $figures->visitors, 'start' => $figures->startRate($minimum), 'registration' => $registration, 'activation' => $figures->activationRate($minimum)],
            ];
        };

        $rows = array_map(function (array $source) use ($row, $names): array {
            $key = $source['keys']['source_key'];

            return is_string($key)
                ? $row($key, AnalyticsSource::label($key, $names), AnalyticsSource::medium($key), AnalyticsSource::group($key), AnalyticsSource::basis($key, $source['visits'], $source['tagged']), $source['figures'])
                : $row(null, 'Not recorded', '(none)', 'Unattributed', 'Visits before sources were recorded', $source['figures']);
        }, $table['rows']);

        usort($rows, fn (array $a, array $b): int => [$b['sort'][$sort] === null ? 0 : 1, $b['sort'][$sort], $b['sort']['visitors']] <=> [$a['sort'][$sort] === null ? 0 : 1, $a['sort'][$sort], $a['sort']['visitors']]);

        $rest = $table['rest'];

        if ($rest !== null) {
            $rows[] = $row(null, AnalyticsSource::otherLabel($rest['tagged']), 'referral', 'Organic', $rest['tagged'] > 0 ? AnalyticsSource::share($rest['visits'], $rest['tagged']) : 'Referrer', $rest['figures']);
        }

        return [
            'rows' => $rows,
            'lit' => in_array(true, array_column($rows, 'selected'), true),
            'minimum' => $minimum,
            'total' => [
                'visitors' => self::count($all->visitors),
                'cells' => [
                    self::count($all->starters).' · '.self::percent($all->startRate()),
                    self::count($all->completers).' · '.self::percent($all->completion()),
                    self::count($all->accounts).' · '.self::percent($all->accountRate()),
                    self::count($all->activated).' · '.self::percent($all->activationRate()),
                ],
                'unattributed' => self::percent(VisitorFigures::rate($quality['direct'], $quality['visitors'])).' unattributed',
            ],
        ];
    }

    /**
     * What shared results brought, for the period and the device alone: the
     * clicks on a result's share buttons and the results shared, the people
     * who came through a shared result, and how far they went.
     *
     * @param  array{rows: list<array{keys: array<string, int|string|null>, figures: VisitorFigures, visits: int, tagged: int}>, rest: mixed}  $table
     * @return list<array{label: string, value: string, rate: string}>
     */
    private function referral(AnalyticsFilter $base, array $table): array
    {
        $shares = $this->visits->resultShares($base);
        $minimum = (int) config('analytics.row_min_denominator');
        $listed = array_values(array_filter($table['rows'], fn (array $row): bool => $row['keys']['source_key'] === AnalyticsSource::SHARED));
        $shared = $listed !== []
            ? $listed[0]['figures']
            : $this->visits->figures(new AnalyticsFilter($base->period, $base->device, AnalyticsSource::SHARED));
        $rate = fn (int $count, int $of): string => self::percent(VisitorFigures::rate($count, $of, $minimum), '');

        return [
            ['label' => 'Share clicks', 'value' => self::count($shares['clicks']), 'rate' => ''],
            ['label' => 'Results shared', 'value' => self::count($shares['results']), 'rate' => ''],
            ['label' => 'Visits from shared results', 'value' => self::count($shared->visitors), 'rate' => $rate($shared->visitors, $shares['results'])],
            ['label' => 'Game starts', 'value' => self::count($shared->starters), 'rate' => $rate($shared->starters, $shared->visitors)],
            ['label' => 'Completions', 'value' => self::count($shared->completers), 'rate' => $rate($shared->completers, $shared->starters)],
            ['label' => 'Accounts', 'value' => self::count($shared->accounts), 'rate' => $rate($shared->accounts, $shared->newVisitors)],
            ['label' => 'Activated users', 'value' => self::count($shared->activated), 'rate' => $rate($shared->activated, $shared->newVisitors)],
        ];
    }

    /**
     * Why the figures can differ from an ad network's: the visitors nothing
     * names, the paid clicks of crawlers and of pages that never ran, and
     * what the paid sources cost.
     *
     * @param  array{visitors: int, direct: int, paidClicks: int, crawlers: int, neverRan: int, spend: string, spendEstimated: string}  $quality
     * @return list<array{icon: string, title: string, text: string}>
     */
    private static function notes(array $quality): array
    {
        $clicks = fn (int $count): string => self::count($count).($count === 1 ? ' paid click' : ' paid clicks');
        $spent = TrafficMoney::of($quality['spend']);
        $estimated = TrafficMoney::of($quality['spendEstimated']);

        return [
            ['icon' => 'circle-help', 'title' => 'Unattributed', 'text' => self::percent(VisitorFigures::rate($quality['direct'], $quality['visitors'])).' of visitors arrived without tags or a referrer. They stay Direct / Unknown; no source is guessed.'],
            ['icon' => 'bot', 'title' => 'Crawlers and previews', 'text' => $quality['crawlers'] === 0
                ? 'No paid click came from a crawler or a link preview.'
                : $clicks($quality['crawlers']).' came from crawlers and link previews. They aren’t counted as visitors.'],
            ['icon' => 'eye-off', 'title' => 'Pages that never ran', 'text' => $quality['neverRan'] === 0
                ? 'Every paid click’s page ran in the browser.'
                : $clicks($quality['neverRan']).' never ran the page in a browser, so nobody is counted for them.'],
            ['icon' => 'circle-dollar-sign', 'title' => 'Spend', 'text' => $spent->isZero()
                ? 'No paid source spent anything in this period. Unpaid visitors cost nothing.'
                : TrafficMoney::format($quality['spend']).' went to paid sources in this period'.($estimated->isZero() ? '' : ', '.TrafficMoney::format($quality['spendEstimated']).' of it estimated').'. Unpaid visitors cost nothing.'],
        ];
    }

    /**
     * What RateGuru records: the three switches of Traffic sources → Sources,
     * the IP database placing the visitors, the networks' APIs connected, and
     * when the first visit was recorded.
     *
     * @return array{rows: list<array{name: string, note: string|null, state: string, tone: string, dot: bool}>, url: string}
     */
    private function tracking(): array
    {
        $settings = TrafficSetting::current();
        $capture = (bool) $settings->capture_enabled;
        $geo = $this->geo->read($settings->geo_edition ?? GeoEdition::GeoLite2City);
        $statuses = TrafficApiConnection::query()->get(['id', 'status'])->countBy(fn (TrafficApiConnection $connection): string => $connection->status->value);
        $connected = (int) ($statuses[TrafficConnectionStatus::Connected->value] ?? 0);
        $attention = (int) ($statuses[TrafficConnectionStatus::NeedsAttention->value] ?? 0);
        $since = $this->view->recordedSince();
        $switch = fn (string $name, string $note, bool $on): array => ['name' => $name, 'note' => $note, 'state' => $on ? 'On' : 'Off', 'tone' => $on ? 'success' : 'neutral', 'dot' => $on];

        return [
            'rows' => [
                $switch('Record visits', 'Game visits and paid clicks', $capture),
                $switch('Record site visits', $capture ? 'Every other page of the site' : 'Records only while Record visits is on', $capture && (bool) $settings->site_capture_enabled),
                $switch('Send postbacks', 'Conversions reported back to the networks', (bool) $settings->postbacks_enabled),
                [
                    'name' => 'IP database',
                    'note' => $geo['serving'] === null ? 'No country or city until one is fetched' : $geo['serving']->label().(isset($geo['installed']['version']) ? ' · build '.$geo['installed']['version'] : ''),
                    'state' => $geo['serving'] === null ? 'None yet' : 'Serving',
                    'tone' => $geo['serving'] === null ? 'warning' : 'success',
                    'dot' => $geo['serving'] !== null,
                ],
                [
                    'name' => 'API connections',
                    'note' => 'Spend and campaigns read from the networks',
                    'state' => match (true) {
                        $attention > 0 => $attention.' need'.($attention === 1 ? 's' : '').' attention',
                        $connected > 0 => $connected.' connected',
                        default => 'None',
                    },
                    'tone' => match (true) {
                        $attention > 0 => 'warning',
                        $connected > 0 => 'success',
                        default => 'neutral',
                    },
                    'dot' => $attention === 0 && $connected > 0,
                ],
                ['name' => 'Visits recorded since', 'note' => null, 'state' => $since === null ? 'No visits yet' : $since->format('d M Y'), 'tone' => 'outline', 'dot' => false],
            ],
            'url' => TrafficSourcesPage::getUrl(['tab' => 'sources']),
        ];
    }

    /**
     * What the source and campaign chosen are called, to say what the funnel
     * and chart are of; empty with neither.
     *
     * @param  array<int, string>  $names  the paid sources' names, by id
     * @param  array<int, string>  $campaignNames  the paid campaigns' names, by id
     */
    private function scope(AnalyticsFilter $filter, array $names, array $campaignNames): string
    {
        $parts = [];

        if ($filter->sourceKey !== null) {
            $parts[] = AnalyticsSource::label($filter->sourceKey, $names);
        }

        if ($filter->campaignKey !== null) {
            $parts[] = CampaignKey::label($filter->campaignKey, $campaignNames);
        }

        return implode(' · ', $parts);
    }

    /** @return array{label: string, value: string, unit: string, change: string|null, flag: array{label: string, hint: string}|null} */
    private static function tile(string $label, string $value, string $unit, ?string $change = null, ?array $flag = null): array
    {
        return ['label' => $label, 'value' => $value, 'unit' => $unit, 'change' => $change, 'flag' => $flag];
    }

    private static function count(int $count): string
    {
        return number_format($count);
    }

    private static function visitors(int $count): string
    {
        return self::count($count).($count === 1 ? ' visitor' : ' visitors');
    }

    private static function percent(?float $rate, string $none = '—'): string
    {
        return $rate === null ? $none : number_format($rate * 100, 1).'%';
    }
}
