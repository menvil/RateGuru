<?php

namespace App\Support\Analytics;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Analytics → Retention worked out from what RetentionQuery read: new
 * accounts in cohorts by the week they signed up — weeks from Monday, UTC,
 * this one and the eight before it, none before the week of the first
 * account recorded — and how many came back with an action on day 1, 7 and
 * 30 of their account's life, and on any of days 1–7.
 *
 * A cohort is measured on a day only once that day is over for every
 * account of it: its last day plus the window, before today. Until then its
 * cell is not matured, never 0 %; with fewer accounts than a rate needs it
 * reads "n < 30". The rates of the tiles and of the rows below are every
 * matured cohort taken together. The period sets only the accounts —
 * those that signed up in its days, each cohort's share of them, which
 * add up to the period's — while the cohorts stay the last nine weeks.
 *
 * The cohorts can be cut by where the accounts came from: the five sources
 * with the most accounts in the period, then the most in the cohorts, and
 * every other source together, an account credited to no visit being Direct
 * / Unknown; or by the game of the attempt each is credited to — the five
 * games likewise, the rest together, and the accounts that finished no game
 * first. Either way the rows add up to every account of the period. It also
 * writes what the rows of a table say together. Pure: today and every
 * figure are given.
 */
final readonly class RetentionCohorts
{
    /** @var list<int> the days of an account's life the cohorts are measured on */
    public const array WINDOWS = [1, 7, 30];

    /** The day "returned by" counts up to: any day of the account's life from day 1 to this one. */
    public const int BY = 7;

    /** How many weeks of cohorts are read, this one included. */
    public const int WEEKS = 9;

    /** How many sources, and how many games, have a row of their own. */
    public const int TOP = 5;

    /** Every new account. */
    public const string ALL = '';

    /** Every source past the top ones. */
    public const string OTHER_SOURCES = 'other-sources';

    /** Every game past the top ones. */
    public const string OTHER_GAMES = 'other-games';

    /** The accounts credited to no game. */
    public const string NO_GAME = 'no-game';

    /** How a game names its cut of the cohorts: `game:{id}`. */
    public const string GAME = 'game:';

    /** @var array<int, string> the return each window counts, as RetentionQuery names it */
    private const array RETURNS = [1 => 'd1', 7 => 'd7', 30 => 'd30'];

    /** @var list<string> */
    private array $topSources;

    /** @var list<int> */
    private array $topGames;

    /**
     * @param  list<string>  $weeks  the first day of each week, Y-m-d, oldest first (weekStarts())
     * @param  list<array{source: string|null, game: int|null, accounts: int, weeks: list<array{users: int, d1: int, d7: int, d30: int, by7: int, inPeriod: int}>}>  $groups  as RetentionQuery::groups() reads them, a week by its place in $weeks
     * @param  string  $today  Y-m-d, UTC
     * @param  string  $fromDay  the period's first day, Y-m-d
     * @param  string  $toDay  the period's last day, Y-m-d
     * @param  int  $minimum  the fewest accounts a rate is reported for
     */
    public function __construct(
        public array $weeks,
        private array $groups,
        private string $today,
        private string $fromDay,
        private string $toDay,
        private int $minimum,
    ) {
        $this->topSources = array_map('strval', self::top($groups, fn (array $group): string => self::sourceOf($group)));
        $this->topGames = array_map('intval', self::top(array_values(array_filter($groups, fn (array $group): bool => $group['game'] !== null)), fn (array $group): int => (int) $group['game']));
    }

    /**
     * The weeks the cohorts are of: this one and the eight before it, each
     * by its Monday, UTC — none before the week of the first account
     * recorded, since nothing before it was.
     *
     * @return list<string>
     */
    public static function weekStarts(CarbonImmutable $today, ?CarbonImmutable $firstSignup): array
    {
        $current = $today->utc()->startOfDay()->startOfWeek(CarbonInterface::MONDAY);
        $first = $firstSignup?->utc()->startOfDay()->startOfWeek(CarbonInterface::MONDAY);
        $weeks = [];

        for ($back = self::WEEKS - 1; $back >= 0; $back--) {
            $start = $current->subWeeks($back);

            if ($first === null || ! $start->lessThan($first)) {
                $weeks[] = $start->toDateString();
            }
        }

        return $weeks === [] ? [$current->toDateString()] : $weeks;
    }

    /** Whether no account signed up in the weeks or in the period. */
    public function isEmpty(): bool
    {
        return $this->groups === [];
    }

    /** Whether weeks before the first account recorded were left out. */
    public function startsLate(): bool
    {
        return count($this->weeks) < self::WEEKS;
    }

    /**
     * The cuts the cohorts can be shown for besides every account: the top
     * sources, every other source, the top games, every other game, and the
     * accounts credited to no game — each only when it has an account.
     *
     * @return list<string>
     */
    public function dims(): array
    {
        return array_values(array_filter([
            ...$this->topSources,
            self::OTHER_SOURCES,
            ...array_map(fn (int $game): string => self::GAME.$game, $this->topGames),
            self::OTHER_GAMES,
            self::NO_GAME,
        ], fn (string $dim): bool => $this->has($dim)));
    }

    /** The cut asked for when it is one of dims(); every account otherwise. */
    public function read(string $dim): string
    {
        return in_array($dim, $this->dims(), true) ? $dim : self::ALL;
    }

    /** @return list<string> the sources with a row of their own, the most accounts first */
    public function topSources(): array
    {
        return $this->topSources;
    }

    /** @return list<int> the games with a row of their own, the most accounts first */
    public function topGames(): array
    {
        return $this->topGames;
    }

    /** The game a cut names; none for any other cut. */
    public static function gameOf(string $dim): ?int
    {
        return str_starts_with($dim, self::GAME) && ctype_digit(substr($dim, strlen(self::GAME))) ? (int) substr($dim, strlen(self::GAME)) : null;
    }

    /** The accounts of the cut that signed up in the period. */
    public function accounts(string $dim = self::ALL): int
    {
        return array_sum(array_map(fn (array $group): int => $group['accounts'], $this->of($dim)));
    }

    /**
     * Each week's cohort of the cut: its Monday, whether it is still going,
     * its accounts, how many of them signed up inside the period (none for a
     * week outside it), and its share for each window — none until it has
     * matured.
     *
     * @return list<array{start: string, open: bool, users: int, inPeriod: int|null, cells: array<int, RetentionShare|null>}>
     */
    public function cohorts(string $dim = self::ALL): array
    {
        $groups = $this->of($dim);
        $rows = [];

        foreach ($this->weeks as $index => $start) {
            $sums = ['users' => 0, 'd1' => 0, 'd7' => 0, 'd30' => 0, 'inPeriod' => 0];

            foreach ($groups as $group) {
                foreach (array_keys($sums) as $figure) {
                    $sums[$figure] += $group['weeks'][$index][$figure] ?? 0;
                }
            }

            $cells = [];

            foreach (self::WINDOWS as $window) {
                $cells[$window] = $this->matured($start, $window) ? new RetentionShare($sums[self::RETURNS[$window]], $sums['users'], $this->minimum) : null;
            }

            $rows[] = [
                'start' => $start,
                'open' => $start <= $this->today && $this->today <= self::lastDay($start),
                'users' => $sums['users'],
                'inPeriod' => $this->overlapsPeriod($start) ? $sums['inPeriod'] : null,
                'cells' => $cells,
            ];
        }

        return $rows;
    }

    /**
     * Every matured cohort of the cut taken together, for each window — and
     * for any of days 1–7 under `by`: the accounts and how many came back.
     * None for a window no cohort has matured for, or whose matured cohorts
     * have no account of the cut.
     *
     * @return array<int|string, RetentionShare|null>
     */
    public function pooled(string $dim = self::ALL): array
    {
        $groups = $this->of($dim);
        $pooled = [];

        foreach (self::RETURNS + ['by' => 'by7'] as $window => $return) {
            $days = $window === 'by' ? self::BY : (int) $window;
            $users = 0;
            $returned = 0;

            foreach ($this->weeks as $index => $start) {
                if (! $this->matured($start, $days)) {
                    continue;
                }

                foreach ($groups as $group) {
                    $users += $group['weeks'][$index]['users'] ?? 0;
                    $returned += $group['weeks'][$index][$return] ?? 0;
                }
            }

            $pooled[$window] = $users === 0 ? null : new RetentionShare($returned, $users, $this->minimum);
        }

        return $pooled;
    }

    /** Whether any cohort has matured for the window. */
    public function anyMatured(int $window): bool
    {
        return $this->lastMatured($window) !== null;
    }

    /** The Monday of the latest cohort matured for the window; none while none has. */
    public function lastMatured(int $window): ?string
    {
        $last = null;

        foreach ($this->weeks as $start) {
            if ($this->matured($start, $window)) {
                $last = $start;
            }
        }

        return $last;
    }

    /**
     * The sources table: the top sources, then every other source together
     * — each with its accounts of the period and its matured cohorts taken
     * together. Their accounts add up to the period's.
     *
     * @return list<array{dim: string, source: string|null, accounts: int, shares: array<int|string, RetentionShare|null>}>
     */
    public function sourceRows(): array
    {
        $dims = [...$this->topSources, ...($this->has(self::OTHER_SOURCES) ? [self::OTHER_SOURCES] : [])];

        return array_map(fn (string $dim): array => [
            'dim' => $dim,
            'source' => $dim === self::OTHER_SOURCES ? null : $dim,
            'accounts' => $this->accounts($dim),
            'shares' => $this->pooled($dim),
        ], $dims);
    }

    /**
     * The games table: the top games, every other game together, then the
     * accounts credited to no game — each as sourceRows() draws a source.
     *
     * @return list<array{dim: string, game: int|null, kind: string, accounts: int, shares: array<int|string, RetentionShare|null>}>
     */
    public function gameRows(): array
    {
        $dims = [
            ...array_map(fn (int $game): string => self::GAME.$game, $this->topGames),
            ...array_values(array_filter([self::OTHER_GAMES, self::NO_GAME], fn (string $dim): bool => $this->has($dim))),
        ];

        return array_map(fn (string $dim): array => [
            'dim' => $dim,
            'game' => self::gameOf($dim),
            'kind' => match ($dim) {
                self::OTHER_GAMES => 'others',
                self::NO_GAME => 'none',
                default => 'game',
            },
            'accounts' => $this->accounts($dim),
            'shares' => $this->pooled($dim),
        ], $dims);
    }

    /**
     * How the cohorts add up to the period's accounts of the cut: each week
     * reaching into the period with its accounts inside it, their total, and
     * the Monday of the first week when it began before the period — so it
     * counts only from the period's first day. None when no week reaches
     * into the period, or the weeks don't add up to the period's accounts.
     *
     * @return array{weeks: list<array{start: string, accounts: int}>, total: int, partial: string|null}|null
     */
    public function reconciliation(string $dim = self::ALL): ?array
    {
        $weeks = [];

        foreach ($this->cohorts($dim) as $cohort) {
            if ($cohort['inPeriod'] !== null) {
                $weeks[] = ['start' => $cohort['start'], 'accounts' => $cohort['inPeriod']];
            }
        }

        $total = array_sum(array_column($weeks, 'accounts'));

        if ($weeks === [] || $total !== $this->accounts($dim)) {
            return null;
        }

        return ['weeks' => $weeks, 'total' => $total, 'partial' => $weeks[0]['start'] < $this->fromDay ? $weeks[0]['start'] : null];
    }

    /**
     * What the sources table says together: which source's accounts come
     * back most by day 7 and which least, among those with enough accounts
     * in matured cohorts — every other source together left out.
     *
     * @param  list<array{name: string, share: RetentionShare|null}>  $rows  the named sources, the most accounts first
     */
    public static function sourcePhrase(array $rows, int $minimum): string
    {
        $measured = self::measured($rows);

        return match (true) {
            $measured === [] => 'No source has '.$minimum.' users in cohorts old enough to measure day 7 yet.',
            count($measured) === 1 => $measured[0]['name'].' users keep '.$measured[0]['percent'].'% at day 7; no other source has enough users in cohorts old enough to compare.',
            default => self::compared($measured, fn (array $high, array $low): string => $high['name'].' users return most ('.$high['percent'].'% at day 7), '.$low['name'].' users least ('.$low['percent'].'%).', 'Every source with enough users keeps %d%% at day 7.'),
        };
    }

    /**
     * What the games table says together, as sourcePhrase() does for the
     * sources: every other game together left out, the accounts that
     * finished no game counted as a row of their own.
     *
     * @param  list<array{name: string, share: RetentionShare|null}>  $rows
     */
    public static function gamePhrase(array $rows, int $minimum): string
    {
        $measured = self::measured($rows);

        return match (true) {
            $measured === [] => 'No game has '.$minimum.' users in cohorts old enough to measure day 7 yet.',
            count($measured) === 1 => $measured[0]['name'].' keeps '.$measured[0]['percent'].'% of its users at day 7; nothing else has enough users in cohorts old enough to compare.',
            default => self::compared($measured, fn (array $high, array $low): string => $high['name'].' keeps the most users at day 7 ('.$high['percent'].'%), '.$low['name'].' the fewest ('.$low['percent'].'%).', 'Every row with enough users keeps %d%% at day 7.'),
        };
    }

    /**
     * The rows whose day 7 can be reported, with its percent.
     *
     * @param  list<array{name: string, share: RetentionShare|null}>  $rows
     * @return list<array{name: string, percent: int}>
     */
    private static function measured(array $rows): array
    {
        $measured = [];

        foreach ($rows as $row) {
            $percent = $row['share']?->percent();

            if ($percent !== null) {
                $measured[] = ['name' => $row['name'], 'percent' => $percent];
            }
        }

        return $measured;
    }

    /**
     * The highest and the lowest of two or more rows said together — the
     * first of equals each time — or, all alike, that they are.
     *
     * @param  list<array{name: string, percent: int}>  $measured
     * @param  callable(array{name: string, percent: int}, array{name: string, percent: int}): string  $phrase
     */
    private static function compared(array $measured, callable $phrase, string $alike): string
    {
        $high = $measured[0];
        $low = $measured[0];

        foreach ($measured as $row) {
            $high = $row['percent'] > $high['percent'] ? $row : $high;
            $low = $row['percent'] < $low['percent'] ? $row : $low;
        }

        return $high['percent'] === $low['percent'] ? sprintf($alike, $high['percent']) : $phrase($high, $low);
    }

    /**
     * The groups of a cut.
     *
     * @return list<array{source: string|null, game: int|null, accounts: int, weeks: list<array{users: int, d1: int, d7: int, d30: int, by7: int, inPeriod: int}>}>
     */
    private function of(string $dim): array
    {
        return array_values(array_filter($this->groups, fn (array $group): bool => $this->matches($group, $dim)));
    }

    /** Whether a cut has an account in the weeks or in the period. */
    private function has(string $dim): bool
    {
        return $this->of($dim) !== [];
    }

    /** @param  array{source: string|null, game: int|null, accounts: int, weeks: list<array<string, int>>}  $group */
    private function matches(array $group, string $dim): bool
    {
        $game = self::gameOf($dim);

        return match (true) {
            $dim === self::ALL => true,
            $dim === self::OTHER_SOURCES => ! in_array(self::sourceOf($group), $this->topSources, true),
            $dim === self::NO_GAME => $group['game'] === null,
            $dim === self::OTHER_GAMES => $group['game'] !== null && ! in_array($group['game'], $this->topGames, true),
            $game !== null => $group['game'] === $game,
            default => self::sourceOf($group) === $dim,
        };
    }

    /** Whether every account of the week has seen the day of its life the window names come and go before today. */
    private function matured(string $start, int $window): bool
    {
        return CarbonImmutable::parse($start, 'UTC')->addDays(6 + $window)->toDateString() < $this->today;
    }

    private function overlapsPeriod(string $start): bool
    {
        return $start <= $this->toDay && self::lastDay($start) >= $this->fromDay;
    }

    private static function lastDay(string $start): string
    {
        return CarbonImmutable::parse($start, 'UTC')->addDays(6)->toDateString();
    }

    /** @param  array{source: string|null}  $group  an account credited to no visit is Direct / Unknown */
    private static function sourceOf(array $group): string
    {
        return $group['source'] ?? AnalyticsSource::DIRECT;
    }

    /**
     * The keys with the most accounts in the period, then the most in the
     * cohorts, then by key, at most TOP.
     *
     * @template TKey of int|string
     *
     * @param  list<array{source: string|null, game: int|null, accounts: int, weeks: list<array<string, int>>}>  $groups
     * @param  callable(array{source: string|null, game: int|null, accounts: int, weeks: list<array<string, int>>}): TKey  $key
     * @return list<TKey>
     */
    private static function top(array $groups, callable $key): array
    {
        $totals = [];

        foreach ($groups as $group) {
            $each = $key($group);
            $totals[$each] ??= ['key' => $each, 'accounts' => 0, 'users' => 0];
            $totals[$each]['accounts'] += $group['accounts'];
            $totals[$each]['users'] += array_sum(array_column($group['weeks'], 'users'));
        }

        $totals = array_values($totals);
        usort($totals, fn (array $a, array $b): int => [$b['accounts'], $b['users'], (string) $a['key']] <=> [$a['accounts'], $a['users'], (string) $b['key']]);

        return array_slice(array_column($totals, 'key'), 0, self::TOP);
    }
}
