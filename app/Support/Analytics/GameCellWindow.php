<?php

namespace App\Support\Analytics;

use Carbon\CarbonImmutable;

/**
 * A window of a game's figures as what the cells of its days
 * (analytics_game_cells) can give and what only the events and attempts can:
 * the whole UTC days counted whole; the days after them still open, counted
 * up to a moment — usable only while the window starts no later than they do
 * and reaches the moment; and every stretch neither covers — the part days
 * at the ends, the days marked as changed since they were counted, the time
 * since the moment — read live. Beside them, the stretches of the open days'
 * plays: begun before the moment, what came of them since — completed, the
 * goal reached, an account confirmed — is read live too. Pure.
 */
final readonly class GameCellWindow
{
    /**
     * @param  string|null  $closedFrom  the first day counted whole the window reads, Y-m-d; none when it reads none
     * @param  string|null  $closedBefore  the day the days counted whole it reads end before
     * @param  string|null  $openFrom  the first open day it reads as counted up to the moment; none when it reads none
     * @param  string|null  $openThrough  the last open day it reads so: the moment's
     * @param  CarbonImmutable|null  $moment  the moment the open days it reads are counted up to; none when it reads none
     * @param  list<string>  $except  the days among those it would read that changed since they were counted, read live instead
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $live  each stretch read from the events and attempts, from a moment and before another
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $late  each stretch of the open days read as counted, whose plays' later changes are read live
     */
    private function __construct(
        public ?string $closedFrom,
        public ?string $closedBefore,
        public ?string $openFrom,
        public ?string $openThrough,
        public ?CarbonImmutable $moment,
        public array $except,
        public array $live,
        public array $late,
    ) {}

    /**
     * @param  string  $through  the last day every day up to which is counted whole
     * @param  CarbonImmutable|null  $moment  the moment the days after it are counted up to; none when they are not
     * @param  list<string>  $changed  the days marked as changed since they were counted
     */
    public static function of(CarbonImmutable $from, CarbonImmutable $to, string $through, ?CarbonImmutable $moment, array $changed): self
    {
        [$from, $to] = [$from->utc(), $to->utc()];
        $whole = ArrivalWindow::of($from, $to);
        $after = GameCellDays::after($through);
        [$closedFrom, $closedBefore, $openFrom, $openThrough, $start, $end] = [null, null, null, null, null, null];

        if ($whole->fromDay !== null) {
            $before = min((string) $whole->beforeDay, $after);

            if ($whole->fromDay < $before) {
                [$closedFrom, $closedBefore] = [$whole->fromDay, $before];
                [$start, $end] = [self::midnight($whole->fromDay), self::midnight($before)];
            }

            // The open days only from their first on, and only up to the moment: past it, only the events and attempts know.
            $first = max($after, $whole->fromDay);

            if ($moment !== null && self::midnight($first)->lessThan($moment) && $to->greaterThanOrEqualTo($moment)) {
                [$openFrom, $openThrough] = [$first, GameCellDays::dayOf($moment)];
                $start ??= self::midnight($first);
                $end = $moment;
            }
        }

        if ($start === null) {
            return new self(null, null, null, null, null, [], [[$from, $to]], []);
        }

        $except = array_values(array_filter(array_unique($changed), fn (string $day): bool => self::midnight($day)->lessThan($end) && self::midnight($day)->addDay()->greaterThan($start)));
        sort($except);
        $marked = array_map(fn (string $day): array => [self::midnight($day), self::midnight($day)->addDay()], $except);
        $covered = self::without([[$start, $end]], $marked);
        $late = $openFrom !== null ? self::without([[self::midnight($openFrom), $moment]], $marked) : [];

        return new self($closedFrom, $closedBefore, $openFrom, $openThrough, $openFrom !== null ? $moment : null, $except, self::without([[$from, $to]], $covered), $late);
    }

    /** Whether it reads any cell at all. */
    public function readsCells(): bool
    {
        return $this->closedFrom !== null || $this->openFrom !== null;
    }

    private static function midnight(string $day): CarbonImmutable
    {
        return CarbonImmutable::parse($day, 'UTC');
    }

    /**
     * The stretches given less the ones taken away, in order.
     *
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $stretches
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $taken
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private static function without(array $stretches, array $taken): array
    {
        foreach ($taken as [$takenFrom, $takenBefore]) {
            $left = [];

            foreach ($stretches as [$stretchFrom, $stretchBefore]) {
                if ($takenBefore->lessThanOrEqualTo($stretchFrom) || $takenFrom->greaterThanOrEqualTo($stretchBefore)) {
                    $left[] = [$stretchFrom, $stretchBefore];

                    continue;
                }

                if ($stretchFrom->lessThan($takenFrom)) {
                    $left[] = [$stretchFrom, $takenFrom];
                }

                if ($takenBefore->lessThan($stretchBefore)) {
                    $left[] = [$takenBefore, $stretchBefore];
                }
            }

            $stretches = $left;
        }

        usort($stretches, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $stretches;
    }
}
