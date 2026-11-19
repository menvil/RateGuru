<?php

namespace App\Filament\Pages\Analytics;

use App\Enums\GameMechanic;
use App\Filament\Pages\GrowthAnalyticsPage;
use App\Models\Game;
use App\Queries\Analytics\RetentionQuery;
use App\Support\Analytics\AnalyticsFilter;
use App\Support\Analytics\AnalyticsSource;
use App\Support\Analytics\RetentionCohorts;
use App\Support\Analytics\RetentionShare;
use Carbon\CarbonImmutable;

/**
 * Analytics → Retention, as the reference draws it: whether new accounts
 * come back and do something — a vote, a comment, a post or a game played on
 * a later day of their account's life. The tiles give the new accounts of
 * the period, the members who came back in it, and how many of every
 * matured cohort came back on day 1, 7 and 30; the cohorts are the accounts
 * of each of the last nine weeks by the week they signed up, cut by a source
 * or a game when one is picked; and two tables give, for the sources and the
 * games accounts came through, their accounts of the period and their
 * matured cohorts' returns, with what each table says together.
 *
 * Read from the accounts, not from the visits (RetentionQuery,
 * RetentionCohorts): only confirmed new accounts of members count, by the
 * moment they signed up. The period sets the accounts — the New users tile,
 * the Accounts columns and the line that adds the cohorts up to it, drawn on
 * the default period with every device as the reference draws it — while
 * the cohorts stay the last nine weeks. The device is that of the visit an
 * account is credited to, the source that visit's session's; the source and
 * campaign filters don't apply, as the page says. With no account at all,
 * the tab says so once. Reads only.
 */
final class RetentionTab implements AnalyticsTab
{
    /** What a qualifying return is, after the reference's bold lead. */
    public const string DEFINITION = 'a visit on a later day with at least one vote, comment, post or game played. Having an account, staying logged in or receiving an email doesn’t count.';

    public function __construct(
        private readonly RetentionQuery $retention,
        private readonly AnalyticsView $view,
    ) {}

    /**
     * The cut of the cohorts as the address may name it: a source's key, a
     * game as `game:{id}`, every other source, every other game, or the
     * accounts that finished no game — anything else every account. Whether
     * it has a row is the tab's to say.
     */
    public static function readDim(mixed $value): string
    {
        return match (true) {
            ! is_string($value) => RetentionCohorts::ALL,
            in_array($value, [RetentionCohorts::OTHER_SOURCES, RetentionCohorts::OTHER_GAMES, RetentionCohorts::NO_GAME], true) => $value,
            preg_match('/^game:[1-9]\d{0,17}$/', $value) === 1 => $value,
            default => AnalyticsSource::read($value) ?? RetentionCohorts::ALL,
        };
    }

    /** @return array<string, mixed> */
    public function data(GrowthAnalyticsPage $page, AnalyticsFilter $filter): array
    {
        $period = $filter->period;
        $minimum = (int) config('analytics.row_min_denominator');
        $first = $this->retention->firstSignup();
        $weeks = RetentionCohorts::weekStarts($period->today, $first);
        $groups = $first === null ? [] : $this->retention->groups(array_map(fn (string $day): CarbonImmutable => CarbonImmutable::parse($day, 'UTC'), $weeks), $period, $filter->device);
        $cohorts = new RetentionCohorts($weeks, $groups, $period->today->toDateString(), $period->fromDay, $period->toDay, $minimum);

        if ($cohorts->isEmpty()) {
            return ['empty' => true, 'range' => $period->range(), 'filtered' => $filter->device !== null];
        }

        $dim = $cohorts->read(self::readDim($page->dim));
        $sourceNames = $this->view->sourceNames($cohorts->topSources());
        $games = Game::query()->whereKey($cohorts->topGames())->get(['id', 'title', 'mechanic'])->keyBy('id');
        $periodName = $period->isCustom() ? $period->range() : mb_strtolower($period->label());
        $since = self::week($weeks[0]);
        $dimName = $this->dimName($dim, $sourceNames, fn (int $id): string => $games->get($id)->title ?? 'Game #'.$id);
        // The line adding the cohorts up is the reference's: on Last 30 days, every device.
        $reconciliation = $period->preset === '30d' && $filter->device === null ? $cohorts->reconciliation($dim) : null;

        $sourceRows = array_map(function (array $row) use ($cohorts, $dim, $sourceNames): array {
            $key = $row['source'];

            return $this->tableRow($cohorts, $row, $dim,
                $key === null ? 'Other sources' : AnalyticsSource::label($key, $sourceNames),
                $key === null ? 'Every other source' : AnalyticsSource::medium($key),
            );
        }, $cohorts->sourceRows());
        $gameRows = array_map(function (array $row) use ($cohorts, $dim, $games): array {
            $game = $row['game'] === null ? null : $games->get($row['game']);

            return $this->tableRow($cohorts, $row, $dim, ...match ($row['kind']) {
                'others' => ['Other games', 'Every other game'],
                'none' => ['Other website experiences', 'No game finished first'],
                default => [$game->title ?? 'Game #'.$row['game'], $game?->mechanic instanceof GameMechanic ? $game->mechanic->label() : 'Game'],
            });
        }, $cohorts->gameRows());

        return [
            'empty' => false,
            'definition' => self::DEFINITION,
            'kpis' => $this->kpis($cohorts, $this->retention->returning($period, $filter->device), $periodName.($filter->device === null ? '' : ' · '.$filter->device->label())),
            'cohorts' => [
                'title' => 'Registration cohorts · '.$dimName,
                'note' => implode(' ', array_filter([
                    'Users who registered each week, and the share who came back on day 1, 7 and 30. Data through '.self::week($period->today->toDateString()).'.',
                    $filter->device === null ? null : $filter->device->label().' only: the device of the visit each account is credited to.',
                    $cohorts->startsLate() && $first !== null ? 'Accounts are followed from '.$first->utc()->format('d M Y').', when the first sign-up was recorded.' : null,
                    'Pick a source or game below to filter.',
                ])),
                'chips' => $this->chips($cohorts, $dim, $dimName, $sourceNames),
                'rows' => $this->cohortRows($cohorts, $dim, $reconciliation['partial'] ?? null, $period->fromDay, $periodName),
                'minimum' => $minimum,
                'reconciliation' => $reconciliation === null ? null : self::reconciliation($reconciliation, $period->label().', '.$period->range(), $dim === RetentionCohorts::ALL ? 'New users above' : 'the '.$dimName.' row below', $period->fromDay),
            ],
            'tables' => [
                [
                    'key' => 'sources',
                    'title' => 'Retention by acquisition source',
                    'first' => 'Source',
                    'note' => 'Accounts: '.$periodName.', by the day they signed up. Rates and returns: every cohort old enough to measure, since '.$since.'. A source is where the session of the visit an account is credited to began; Direct / Unknown when none was recorded. Click a row to see its cohorts.',
                    'rows' => $sourceRows,
                    'answer' => RetentionCohorts::sourcePhrase(self::phraseRows(array_values(array_filter($sourceRows, fn (array $row): bool => $row['dim'] !== RetentionCohorts::OTHER_SOURCES))), $minimum),
                ],
                [
                    'key' => 'games',
                    'title' => 'Retention by originating game',
                    'first' => 'First experience',
                    'note' => 'Where the user started before registering: the game they finished before signing up. Accounts: '.$periodName.'; rates and returns: cohorts since '.$since.'. Click a row to see its cohorts.',
                    'rows' => $gameRows,
                    'answer' => RetentionCohorts::gamePhrase(self::phraseRows(array_values(array_filter($gameRows, fn (array $row): bool => $row['dim'] !== RetentionCohorts::OTHER_GAMES))), $minimum),
                ],
            ],
        ];
    }

    /**
     * The tiles: the period's new accounts, the members who came back in it,
     * and every matured cohort's return on day 1, 7 and 30.
     *
     * @return list<array{label: string, value: string, note: string}>
     */
    private function kpis(RetentionCohorts $cohorts, int $returning, string $scope): array
    {
        $pooled = $cohorts->pooled();
        $tile = function (int $window) use ($cohorts, $pooled): array {
            $share = $pooled[$window] ?? null;
            $last = $cohorts->lastMatured($window);

            return [
                'label' => 'Day '.$window.' retention',
                'value' => self::shareText($share, $cohorts),
                'note' => match (true) {
                    $last === null => 'No cohort is old enough yet',
                    $window === max(RetentionCohorts::WINDOWS) => 'Cohorts up to '.self::week($last).' · '.number_format($share->users ?? 0).' users',
                    default => 'Matured cohorts · '.number_format($share->users ?? 0).' users',
                },
            ];
        };

        return [
            ['label' => 'New users', 'value' => number_format($cohorts->accounts()), 'note' => 'Accounts created · '.$scope],
            ['label' => 'Returning users', 'value' => number_format($returning), 'note' => 'Registered users with a qualifying return'],
            ...array_map($tile, RetentionCohorts::WINDOWS),
        ];
    }

    /**
     * The cuts offered above the cohorts: every account, the top sources,
     * and the cut chosen when it is none of those.
     *
     * @param  array<int, string>  $sourceNames
     * @return list<array{dim: string, label: string, on: bool}>
     */
    private function chips(RetentionCohorts $cohorts, string $dim, string $dimName, array $sourceNames): array
    {
        $chips = [['dim' => RetentionCohorts::ALL, 'label' => 'All new users', 'on' => $dim === RetentionCohorts::ALL]];

        foreach ($cohorts->topSources() as $key) {
            $chips[] = ['dim' => $key, 'label' => AnalyticsView::sourceLabel($key, $sourceNames), 'on' => $dim === $key];
        }

        if (! in_array($dim, array_column($chips, 'dim'), true)) {
            $chips[] = ['dim' => $dim, 'label' => $dimName, 'on' => true];
        }

        return $chips;
    }

    /**
     * The cohorts of the cut as the table draws them: each week's Monday —
     * the week still going said so — its accounts, and a cell per window.
     * The week reaching back before the period, while the line adding the
     * weeks up is drawn, says how many of its accounts are inside it.
     *
     * @return list<array{key: string, label: string, note: string|null, users: string, cells: list<array{value: string, title: string, tone: string, heat: int, dark: bool}>}>
     */
    private function cohortRows(RetentionCohorts $cohorts, string $dim, ?string $partial, string $fromDay, string $periodName): array
    {
        $rows = [];

        foreach ($cohorts->cohorts($dim) as $cohort) {
            $rows[] = [
                'key' => $cohort['start'],
                'label' => self::week($cohort['start']).($cohort['open'] ? ' · open' : ''),
                'note' => match (true) {
                    $cohort['open'] => 'Registrations so far this week',
                    $cohort['start'] === $partial => number_format($cohort['inPeriod'] ?? 0).' of them from '.self::week($fromDay).', inside the '.$periodName,
                    default => null,
                },
                'users' => number_format($cohort['users']),
                'cells' => array_map(fn (int $window): array => self::cell($cohort['cells'][$window] ?? null, $window), RetentionCohorts::WINDOWS),
            ];
        }

        return $rows;
    }

    /**
     * A cohort's cell: "—" until it has matured, "n < 30" with too few
     * accounts, else the share that came back, shaded the darker the more
     * came back, as the reference shades it.
     *
     * @return array{value: string, title: string, tone: string, heat: int, dark: bool}
     */
    private static function cell(?RetentionShare $share, int $window): array
    {
        if ($share === null) {
            return ['value' => '—', 'title' => 'Not matured: day '.$window.' hasn’t passed for this whole cohort', 'tone' => 'none', 'heat' => 0, 'dark' => false];
        }

        $percent = $share->percent();

        if ($percent === null) {
            return ['value' => 'n < '.$share->minimum, 'title' => 'Too few users to report a rate', 'tone' => 'small', 'heat' => 0, 'dark' => false];
        }

        $alpha = 0.06 + min(1, $percent / 45) * 0.55;

        return [
            'value' => $percent.'%',
            'title' => number_format($share->returned).' of '.number_format($share->users).' users returned',
            'tone' => 'heat',
            'heat' => (int) round($alpha * 100),
            'dark' => $alpha > 0.34,
        ];
    }

    /**
     * A row of either table: its name and what it is, its accounts of the
     * period, its matured cohorts' return on day 1, 7 and 30 and by day 7,
     * whether it is the cut shown, and the cut a click on it shows — every
     * account again when it is.
     *
     * @param  array{dim: string, accounts: int, shares: array<int|string, RetentionShare|null>}  $row
     * @return array{dim: string, name: string, sub: string, accounts: string, rates: list<array{value: string, title: string, quiet: bool, small: bool, strong: bool}>, kept: array{value: string, title: string, quiet: bool, small: bool}, chosen: bool, toggle: string, share: RetentionShare|null}
     */
    private function tableRow(RetentionCohorts $cohorts, array $row, string $dim, string $name, string $sub): array
    {
        $by = $row['shares']['by'] ?? null;
        $last = $cohorts->lastMatured(RetentionCohorts::BY);
        $chosen = $row['dim'] === $dim;

        return [
            'dim' => $row['dim'],
            'name' => $name,
            'sub' => $sub,
            'accounts' => number_format($row['accounts']),
            'rates' => array_map(function (int $window) use ($cohorts, $row): array {
                $share = $row['shares'][$window] ?? null;
                $percent = $share?->percent();

                return [
                    'value' => self::shareText($share, $cohorts),
                    'title' => match (true) {
                        $share === null && ! $cohorts->anyMatured($window) => 'Not matured: no cohort has reached day '.$window.' yet',
                        $share === null => 'No users in cohorts old enough to measure',
                        $percent === null => 'Only '.number_format($share->users).' users in matured cohorts, too few to report a rate',
                        default => 'From '.number_format($share->users).' users in matured cohorts',
                    },
                    'quiet' => $percent === null,
                    'small' => $share !== null && $percent === null,
                    'strong' => $percent !== null && $window === RetentionCohorts::BY,
                ];
            }, RetentionCohorts::WINDOWS),
            'kept' => match (true) {
                $by === null => ['value' => '—', 'title' => $last === null ? 'Not matured' : 'No users in cohorts old enough to measure', 'quiet' => true, 'small' => false],
                $by->isSmall() => ['value' => 'n < '.$by->minimum, 'title' => 'Too few users in cohorts old enough to measure', 'quiet' => true, 'small' => true],
                default => [
                    'value' => number_format($by->returned).' of '.number_format($by->users),
                    'title' => number_format($by->returned).' of '.number_format($by->users).' users from the '.self::week($cohorts->weeks[0]).' — '.self::week((string) $last).' cohorts came back by day '.RetentionCohorts::BY,
                    'quiet' => false,
                    'small' => false,
                ],
            },
            'chosen' => $chosen,
            'toggle' => $chosen ? RetentionCohorts::ALL : $row['dim'],
            'share' => $row['shares'][RetentionCohorts::BY] ?? null,
        ];
    }

    /** A share as a cell or a tile writes it: "—" with none, "n < 30" with too few accounts, else a whole percent. */
    private static function shareText(?RetentionShare $share, RetentionCohorts $cohorts): string
    {
        $percent = $share?->percent();

        return match (true) {
            $share === null => '—',
            $percent === null => 'n < '.$share->minimum,
            default => $percent.'%',
        };
    }

    /**
     * The rows a table's sentence speaks of, each by its name — with its
     * medium where two rows share a name — and its day 7.
     *
     * @param  list<array{name: string, sub: string, share: RetentionShare|null}>  $rows
     * @return list<array{name: string, share: RetentionShare|null}>
     */
    private static function phraseRows(array $rows): array
    {
        $counts = array_count_values(array_column($rows, 'name'));

        return array_map(fn (array $row): array => [
            'name' => ($counts[$row['name']] ?? 0) > 1 ? $row['name'].' · '.$row['sub'] : $row['name'],
            'share' => $row['share'],
        ], $rows);
    }

    /**
     * What the cut chosen is called above its cohorts.
     *
     * @param  array<int, string>  $sourceNames
     * @param  callable(int): string  $gameName
     */
    private function dimName(string $dim, array $sourceNames, callable $gameName): string
    {
        $game = RetentionCohorts::gameOf($dim);

        return match (true) {
            $dim === RetentionCohorts::ALL => 'All new users',
            $dim === RetentionCohorts::OTHER_SOURCES => 'Other sources',
            $dim === RetentionCohorts::OTHER_GAMES => 'Other games',
            $dim === RetentionCohorts::NO_GAME => 'Other website experiences',
            $game !== null => $gameName($game),
            default => AnalyticsView::sourceLabel($dim, $sourceNames),
        };
    }

    /**
     * The line adding the cohorts up to the period's accounts:
     * “Last 30 days, 20 Oct — 18 Nov 2026: 12 + 30 = 42 accounts, the same
     * as New users above. The 19 Oct week counts from 20 Oct.”
     *
     * @param  array{weeks: list<array{start: string, accounts: int}>, total: int, partial: string|null}  $reconciliation
     */
    private static function reconciliation(array $reconciliation, string $period, string $same, string $fromDay): string
    {
        $parts = implode(' + ', array_map(fn (array $week): string => number_format($week['accounts']), $reconciliation['weeks']));
        $line = $period.': '.$parts.' = '.number_format($reconciliation['total']).' '.str('account')->plural($reconciliation['total']).', the same as '.$same.'.';

        return $reconciliation['partial'] === null ? $line : $line.' The '.self::week($reconciliation['partial']).' week counts from '.self::week($fromDay).'.';
    }

    /** A day as the tab names a week or a day: “10 Aug”. */
    private static function week(string $day): string
    {
        return CarbonImmutable::parse($day, 'UTC')->format('d M');
    }
}
