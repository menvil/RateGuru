<?php

namespace App\Filament\Pages\Analytics;

use App\Enums\AnalyticsDimension;
use App\Enums\ComparisonEvidence;
use App\Enums\GameAudience;
use App\Enums\GameStatus;
use App\Enums\PeriodMetric;
use App\Filament\Pages\Experiments\ExperimentsView;
use App\Filament\Pages\GrowthAnalyticsPage;
use App\Models\Game;
use App\Models\GameTrafficPeriod;
use App\Models\GameVersion;
use App\Queries\Analytics\AnalyticsVisitsQuery;
use App\Queries\Analytics\LandingExperiencesQuery;
use App\Queries\Games\ComparisonPeriodsQuery;
use App\Queries\Games\GameMetricsQuery;
use App\Queries\Games\TrafficPeriodScopesQuery;
use App\Support\Analytics\ActivityDay;
use App\Support\Analytics\AnalyticsFilter;
use App\Support\Analytics\AnalyticsPeriod;
use App\Support\Analytics\ComparedVersion;
use App\Support\Analytics\ComparisonVerdict;
use App\Support\Analytics\LandingRoute;
use App\Support\Analytics\PeriodComparison;
use App\Support\Analytics\VisitorFigures;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Analytics → Landings, as the reference draws it: one row per page a
 * session began on and the experience it showed — a game's page with the
 * version it played, a result shared from it, the feed, a post — with
 * everything the session went on to do counted there, a game started from
 * the feed included. Each row names where the page is, what its version had
 * of the game's traffic in the days, the game and the version's flow, then
 * its visitors and how many started, completed, made an account and
 * activated one — the last two of new visitors — each rate "—" below the
 * denominator a row needs, and activation “Too early” while none of the
 * row's accounts has had its days to activate. A game's row leads to the
 * game's own analytics on the Games tab, of the same days and device. Every
 * filter of the page applies; the most people first, the landings past the
 * first rows counted together.
 *
 * Beside them, the comparison started last of those still open: each
 * version against the reference version, read from the visitors each was
 * assigned — as Analytics → Experiments reads them, which it leads to — so
 * no filter applies to it. None while no comparison is open. Nobody matching
 * the filters is said once, with nothing else drawn. Reads only.
 */
final class LandingsTab implements AnalyticsTab
{
    /** How many landings the table lists, the most people first; the rest are counted together after them. */
    public const int ROWS = 25;

    /** How many of a version's scopes its note names before saying how many more. */
    private const int NOTED_SCOPES = 3;

    public const string DESCRIPTION = 'One row per landing and experience: the page a session began on, with all the session went on to do. Start rate uses visitors; completion uses starters; registration and activation use new visitors.';

    public function __construct(
        private readonly AnalyticsVisitsQuery $visits,
        private readonly LandingExperiencesQuery $experiences,
        private readonly ComparisonPeriodsQuery $periods,
        private readonly GameMetricsQuery $metrics,
        private readonly TrafficPeriodScopesQuery $scopes,
    ) {}

    /** @return array<string, mixed> */
    public function data(GrowthAnalyticsPage $page, AnalyticsFilter $filter): array
    {
        $period = $filter->period;
        $table = $this->visits->by($filter, AnalyticsDimension::Landing, self::ROWS);

        if ($table['rows'] === []) {
            return ['empty' => true, 'range' => $period->range(), 'filtered' => $filter->device !== null || $filter->narrowsSource()];
        }

        $keys = array_map(fn (array $row): ?string => is_string($row['keys']['landing_key']) ? $row['keys']['landing_key'] : null, $table['rows']);
        $parts = array_map(fn (?string $key): ?array => $key === null ? null : LandingRoute::parts($key), $keys);
        $gameIds = array_values(array_unique(array_filter(array_map(fn (?array $part): ?int => $part['gameId'] ?? null, $parts))));
        $versionIds = array_values(array_unique(array_filter(array_map(fn (?array $part): ?int => $part['versionId'] ?? null, $parts))));
        $games = $this->experiences->games($gameIds);
        $versions = $this->experiences->versions($versionIds);
        $shared = $this->experiences->periods($gameIds, $period->start(), $period->end());
        $scopes = $this->scopes->labels($shared);
        $periods = $shared->groupBy('game_id');
        $minimum = (int) config('analytics.row_min_denominator');
        $rows = [];
        $early = [];

        foreach ($table['rows'] as $index => $row) {
            $key = $keys[$index];
            $part = $parts[$index];
            $game = $part === null || $part['gameId'] === null ? null : $games->get($part['gameId']);
            $version = $part === null || $part['versionId'] === null ? null : $versions->get($part['versionId']);
            // A version is read only as its own game's.
            $version = $version !== null && $game !== null && (int) $version->game_id === (int) $game->id ? $version : null;
            $experience = match (true) {
                $version !== null => self::experience($version),
                $key !== null && ! LandingRoute::showsGame($key) => LandingRoute::page($key),
                default => null,
            };
            $line = self::row($row['figures'], $minimum, [
                'key' => $key,
                'path' => $key === null ? 'Not recorded' : LandingRoute::path($key, $game?->slug),
                'note' => match (true) {
                    $key === null => 'Visits before landings were recorded',
                    $game !== null => self::note($game, $version, $periods->get((int) $game->id, new Collection), $scopes, $period->today),
                    LandingRoute::showsGame($key) => null,
                    default => 'Starts count any game',
                },
                'game' => match (true) {
                    $game !== null => $game->title,
                    $key !== null && LandingRoute::showsGame($key) => '—',
                    default => 'No game',
                },
                'gameUrl' => $game === null || $game->status === GameStatus::Archived ? null : GamesTab::url((int) $game->id, $filter),
                'experience' => $experience ?? '—',
            ]);

            if ($line['tooEarly']) {
                // A version row by its experience, a page of the site by its name, the rest by their path.
                $early[] = $experience ?? $line['path'];
            }

            $rows[] = $line;
        }

        if ($table['rest'] !== null) {
            $line = self::row($table['rest']['figures'], $minimum, [
                'key' => null,
                'path' => 'Other landing pages',
                'note' => 'Every landing past the first '.self::ROWS.', each visitor once',
                'game' => '—',
                'gameUrl' => null,
                'experience' => '—',
            ]);

            if ($line['tooEarly']) {
                $early[] = $line['path'];
            }

            $rows[] = $line;
        }

        return [
            'empty' => false,
            'description' => self::DESCRIPTION,
            'rows' => $rows,
            'foot' => self::foot($period, array_values(array_unique($early)), $minimum, count($rows) > 1 && count($early) === count($rows)),
            'comparison' => $this->comparison(),
        ];
    }

    /**
     * A row of the table: where and what the landing is, then its visitors
     * and rates, each with how many of how many it divides when it is shown.
     *
     * @param  array{key: string|null, path: string, note: string|null, game: string, gameUrl: string|null, experience: string}  $landing
     * @return array<string, mixed>
     */
    private static function row(VisitorFigures $figures, int $minimum, array $landing): array
    {
        // Activation is no rate yet while none of the row's accounts has had its days to activate.
        $tooEarly = $figures->accounts > 0 && $figures->maturedAccounts === 0 && $figures->activationRate($minimum) !== null;

        return [
            ...$landing,
            'visitors' => number_format($figures->visitors),
            'rates' => [
                self::rate($figures->starters, $figures->visitors, $figures->startRate($minimum)),
                self::rate($figures->completers, $figures->starters, $figures->completion($minimum)),
                self::rate($figures->accounts, $figures->newVisitors, $figures->accountRate($minimum)),
                $tooEarly
                    ? ['value' => 'Too early', 'title' => number_format($figures->accounts).' '.str('account')->plural($figures->accounts).', none old enough yet', 'quiet' => true]
                    : self::rate($figures->activated, $figures->newVisitors, $figures->activationRate($minimum)),
            ],
            'tooEarly' => $tooEarly,
        ];
    }

    /**
     * A rate as a cell shows it, with how many of how many on hover; "—"
     * where it can't be shown.
     *
     * @return array{value: string, title: string|null, quiet: bool}
     */
    private static function rate(int $count, int $of, ?float $rate): array
    {
        return $rate === null
            ? ['value' => '—', 'title' => null, 'quiet' => true]
            : ['value' => number_format($rate * 100, 1).'%', 'title' => number_format($count).' of '.number_format($of), 'quiet' => false];
    }

    /** A version as the experience it plays: “Result First · V7”. */
    private static function experience(GameVersion $version): string
    {
        $flow = $version->flow?->name;

        return ($flow === null ? '' : $flow.' · ').$version->label();
    }

    /**
     * What a game's row had of its traffic in the days: a game paused or
     * archived says since when; else, for every scope the version had a
     * share in — everyone's, or a split's for a source or campaign, by name —
     * its latest share and since when, or its days for a period over: “Only
     * version”, “80% · since 08 Oct”, “ExoClick tests: 50% · since 18 Nov”,
     * everyone's first. Anonymous visitors' periods are read first — a
     * landing is where new visitors arrive — and logged-in players' only
     * where the version had no share of anonymous visitors.
     *
     * @param  Collection<int, GameTrafficPeriod>  $periods  the game's, latest started first
     * @param  array<int, string|null>  $scopes  each period's scope in words, by id
     */
    private static function note(Game $game, ?GameVersion $version, Collection $periods, array $scopes, CarbonImmutable $today): ?string
    {
        $since = $game->status_changed_at === null ? '' : ' '.self::day($game->status_changed_at, $today);

        if ($game->status === GameStatus::Paused || $game->status === GameStatus::Archived) {
            return $game->status->label().$since;
        }

        if ($version === null) {
            return null;
        }

        $own = $periods->filter(fn (GameTrafficPeriod $period): bool => array_key_exists((int) $version->id, $period->shares()));
        $anonymous = $own->filter(fn (GameTrafficPeriod $period): bool => $period->audience === GameAudience::Anonymous);
        // The latest period of each audience and scope, everyone's before the splits'.
        $latest = ($anonymous->isNotEmpty() ? $anonymous : $own)
            ->unique(fn (GameTrafficPeriod $period): string => $period->audience->value.':'.$period->source_id.':'.$period->campaign_id)
            ->sortBy(fn (GameTrafficPeriod $period): int => $period->source_id === null ? 0 : 1)
            ->values();

        if ($latest->isEmpty()) {
            return null;
        }

        $notes = $latest->take(self::NOTED_SCOPES)->map(function (GameTrafficPeriod $period) use ($version, $scopes, $today): string {
            $share = $period->shares()[(int) $version->id];
            $label = implode(' · ', array_filter([
                $period->audience === GameAudience::Authenticated ? 'Logged-in players' : null,
                $scopes[(int) $period->id] ?? null,
            ]));
            // A period over says when it ran, whatever the share: two versions each alone in turn read apart.
            $days = $period->ended_at === null ? null : self::day($period->started_at, $today).' — '.self::day($period->ended_at, $today);
            $text = $share >= 100
                ? ($label === '' ? 'Only version' : 'only version').($days === null ? '' : ' · '.$days)
                : $share.'% · '.($days ?? 'since '.self::day($period->started_at, $today));

            return $label === '' ? $text : $label.': '.$text;
        })->all();
        $more = $latest->count() - self::NOTED_SCOPES;

        return implode('; ', $notes).($more > 0 ? '; '.$more.' more '.str('split')->plural($more) : '');
    }

    /**
     * What the footnote says of activation: the days it needs, the rows none
     * of whose accounts has had them yet, and — for days that reach the
     * latest — since when accounts can still activate; then how people and
     * rates are counted.
     *
     * @param  list<string>  $early  the rows too early to read, by experience, page or path
     * @param  bool  $all  whether every row of the table is too early
     */
    private static function foot(AnalyticsPeriod $period, array $early, int $minimum, bool $all = false): string
    {
        $days = (int) config('analytics.activation_days');
        $matured = ActivityDay::maturedBefore($period->today, $days);
        $foot = 'Activation needs '.$days.' days after signup.';

        if ($early !== [] && $all) {
            $foot .= ' No row has an account that old yet, so activation reads “Too early”.';
        } elseif ($early !== []) {
            // Quoted, since a flow's name may hold a comma of its own.
            $early = array_map(fn (string $name): string => '“'.$name.'”', $early);
            $one = count($early) === 1;
            $last = array_pop($early);
            $foot .= ' '.($early === [] ? $last : implode(', ', $early).' and '.$last).($one ? ' has' : ' have').' no account that old yet, so '.($one ? 'its' : 'their').' activation reads “Too early”.';
        }

        if ($period->toDay >= $matured->toDateString()) {
            $foot .= ' Accounts from '.$matured->format('d M').' on can still activate.';
        }

        return $foot.' A visitor who began sessions on two landings counts on each. A rate shows “—” until the number it divides by reaches '.$minimum.'.';
    }

    /**
     * The comparison started last of those still open, every version against
     * the reference version by the visitors each was assigned: the visitors,
     * then each step's rate and its change against the reference — by design
     * where the version's flow or the audience can't have it — or, while
     * nobody was sent to a version yet, what says so. None while no
     * comparison is open.
     *
     * @return array<string, mixed>|null
     */
    private function comparison(): ?array
    {
        $period = $this->periods->latestOpenComparison();

        if ($period === null) {
            return null;
        }

        $id = (int) $period->id;
        $comparison = ExperimentsView::comparison($period, $period->audience, $this->metrics->periodFunnel([$id], ExperimentsView::maturedBefore())[$id] ?? []);
        $reference = $comparison->reference();

        if ($reference === null) {
            return null;
        }

        $others = array_values(array_filter($comparison->versions, fn (ComparedVersion $version): bool => $version->id !== $reference->id));
        $name = function (ComparedVersion $version) use ($period): string {
            $model = $period->versions->firstWhere('id', $version->id);

            return $model instanceof GameVersion ? self::experience($model) : $version->label();
        };
        // Logged-in players leave no email and make no account: the goal is what they are compared by.
        $metrics = $period->audience === GameAudience::Anonymous
            ? [PeriodMetric::Starts, PeriodMetric::Completions, PeriodMetric::ResultViewed, PeriodMetric::Emails, PeriodMetric::Registrations]
            : [PeriodMetric::Starts, PeriodMetric::Completions, PeriodMetric::ResultViewed, PeriodMetric::Goal];
        $assigned = fn (ComparedVersion $version): array => ['value' => number_format($comparison->funnel($version)->assigned), 'title' => null, 'quiet' => false];
        $rows = [[
            'label' => 'Assigned visitors',
            'reference' => $assigned($reference),
            'groups' => array_map(fn (ComparedVersion $version): array => [...$assigned($version), 'change' => ''], $others),
        ]];

        foreach ($metrics as $metric) {
            $rows[] = [
                'label' => self::metric($metric),
                'reference' => self::figure($comparison, $reference, $metric),
                'groups' => array_map(fn (ComparedVersion $version): array => [
                    ...self::figure($comparison, $version, $metric),
                    'change' => $comparison->evidence($version, $metric) === ComparisonEvidence::ByDesign
                        ? ComparisonEvidence::ByDesign->label()
                        : ComparisonVerdict::change($comparison->relative($version, $metric)),
                ], $others),
            ];
        }

        $scope = $this->scopes->labels([$period])[$id] ?? null;
        $paused = $period->game->status === GameStatus::Paused;
        // Nobody sent to a version yet is said once, in place of a table that would say it in every cell.
        $fresh = $comparison->assigned() === 0;

        return [
            'id' => $id,
            'title' => implode(' vs ', array_map($name, [$reference, ...$others])),
            'description' => ExperimentsView::title($period, $scope).'. Same game, same days, a random split '.lcfirst(ExperimentsView::dates($period, true)).'. '
                .'Rates use everyone assigned, not only those who finished; differences are relative to '.$name($reference).', the version with the largest share. Page filters don’t apply.',
            'status' => $paused ? ['label' => 'Paused', 'tone' => 'warning', 'dot' => false] : ['label' => 'Current', 'tone' => 'success', 'dot' => true],
            'url' => GrowthAnalyticsPage::getUrl(['tab' => 'experiments', 'exp' => $id]),
            'heads' => ['reference' => $name($reference), 'groups' => array_map($name, $others)],
            // The metric column, the reference's, then a figure and a difference for every other version.
            'columns' => '--rg-admin-table-columns: minmax(var(--rg-admin-analytics-metric-width), 1.2fr) minmax(130px, 1fr) repeat('.count($others).', minmax(130px, 1fr) 110px); '
                .'--rg-admin-table-min-width: calc(var(--rg-admin-analytics-metric-width) + '.(130 + 240 * count($others)).'px)',
            'rows' => $fresh ? [] : $rows,
            'fresh' => ! $fresh ? null : ($paused
                ? 'Nobody was sent to a version before the game was paused. Numbers appear once it is live again.'
                : 'This period just started. Numbers appear as visitors arrive.'),
        ];
    }

    /**
     * One version's figure of a metric: its rate, how many of how many on
     * hover — or why it can't be read.
     *
     * @return array{value: string, title: string|null, quiet: bool}
     */
    private static function figure(PeriodComparison $comparison, ComparedVersion $version, PeriodMetric $metric): array
    {
        $rate = $comparison->rate($version, $metric);

        if ($rate === null) {
            return ['value' => $comparison->dash($version, $metric) ?? '—', 'title' => null, 'quiet' => true];
        }

        $funnel = $comparison->funnel($version);

        return ['value' => number_format($rate, 1).'%', 'title' => number_format($funnel->count($metric)).' of '.number_format($funnel->assigned), 'quiet' => false];
    }

    /** A metric as the card names it, as Analytics → Experiments does. */
    private static function metric(PeriodMetric $metric): string
    {
        return match ($metric) {
            PeriodMetric::Starts => 'Started the game',
            PeriodMetric::Completions => 'Completed the game',
            PeriodMetric::ResultViewed => 'Saw the full result',
            PeriodMetric::Emails => 'Left an email',
            PeriodMetric::Registrations => 'Created an account',
            PeriodMetric::Activated => 'Activated within 7 days',
            PeriodMetric::Goal => 'Reached the goal',
        };
    }

    /** A day as a note names it: “08 Oct”, with its year when not this one. */
    private static function day(CarbonImmutable $moment, CarbonImmutable $today): string
    {
        $moment = $moment->utc();

        return $moment->format($moment->year === $today->year ? 'd M' : 'd M Y');
    }
}
