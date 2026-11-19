<?php

namespace App\Support\Traffic\Reports;

use Carbon\CarbonImmutable;
use Closure;

/**
 * The period a report page reads, as its address names it: one of the page's
 * presets, or a range of whole days typed into the period picker. A range is
 * read only when both its days are real dates, the first not after the last,
 * the last not after today and the first not before the oldest day still
 * kept — so never longer than the data is kept. Anything else, the address's
 * or a forged update's, is the page's default preset instead, never an
 * error. Today is given, never read from the clock.
 *
 * Every report page's picker offers the same presets (PRESETS), and a
 * preset's days are the same on each (presetDays()): what differs is the
 * page's day — UTC, or each network's own — and how far back it may read.
 */
final readonly class ReportPeriod
{
    /** What a range of days typed in is called where a preset would be named. */
    public const string CUSTOM = 'custom';

    /** The preset of every day there is data for. */
    public const string ALL = 'all';

    /** @var array<string, string> the presets every report page's picker offers, in order, by the key the address names them by */
    public const array PRESETS = [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        '90d' => 'Last 90 days',
        'this-month' => 'This month',
        'last-month' => 'Last month',
        self::ALL => 'All time',
    ];

    private function __construct(
        public string $preset,
        public ?string $fromDay = null,
        public ?string $toDay = null,
    ) {}

    /**
     * @param  array<string, string>  $presets  the page's presets, by key
     * @param  int  $keptDays  how many days back, today included, the data is kept
     */
    public static function read(array $presets, string $default, mixed $preset, mixed $from, mixed $to, CarbonImmutable $today, int $keptDays): self
    {
        $first = self::day($from);
        $last = self::day($to);
        $today = $today->startOfDay();

        if ($first !== null && $last !== null && $first->lessThanOrEqualTo($last) && $last->lessThanOrEqualTo($today) && $first->greaterThanOrEqualTo($today->subDays($keptDays - 1))) {
            return new self(self::CUSTOM, $first->toDateString(), $last->toDateString());
        }

        return new self(is_string($preset) && array_key_exists($preset, $presets) ? $preset : $default);
    }

    public function isCustom(): bool
    {
        return $this->preset === self::CUSTOM;
    }

    /** The range's first day, at its start; null for a preset. */
    public function start(): ?CarbonImmutable
    {
        return $this->fromDay === null ? null : CarbonImmutable::parse($this->fromDay, 'UTC');
    }

    /** The moment after the range's last day; null for a preset. */
    public function end(): ?CarbonImmutable
    {
        return $this->toDay === null ? null : CarbonImmutable::parse($this->toDay, 'UTC')->addDay();
    }

    /**
     * A preset's first and last day: today or yesterday alone; the last 7,
     * 30 or 90 days up to today; this month up to today; the whole of last
     * month; or all time — from the first day there is data for up to today,
     * never more days than the page reads, and those last ones when there is
     * no data yet. Any other preset is the last 30 days.
     *
     * @param  int  $mostDays  the most days, today included, all time may cover
     * @param  (Closure(): ?string)|null  $firstDay  the first day there is data for, Y-m-d, or null for none yet: asked for all time alone
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function presetDays(string $preset, CarbonImmutable $today, int $mostDays, ?Closure $firstDay = null): array
    {
        $today = $today->startOfDay();

        return match ($preset) {
            'today' => [$today, $today],
            'yesterday' => [$today->subDay(), $today->subDay()],
            '7d' => [$today->subDays(6), $today],
            '90d' => [$today->subDays(89), $today],
            'this-month' => [$today->startOfMonth(), $today],
            'last-month' => [$today->startOfMonth()->subMonthNoOverflow(), $today->startOfMonth()->subDay()],
            self::ALL => [self::allTimeFrom($today, $mostDays, $firstDay === null ? null : $firstDay()), $today],
            default => [$today->subDays(29), $today],
        };
    }

    /**
     * Two days as the period picker and the figures name them: “10 Sep — 09
     * Oct 2026”, each with its year where the years differ, one day alone
     * when they are the same.
     */
    public static function span(CarbonImmutable $first, CarbonImmutable $last): string
    {
        if ($first->isSameDay($last)) {
            return $last->format('d M Y');
        }

        return $first->format($first->year === $last->year ? 'd M' : 'd M Y').' — '.$last->format('d M Y');
    }

    /** All time's first day: the first day there is data for, but no earlier than the most days allow, and no later than today. */
    private static function allTimeFrom(CarbonImmutable $today, int $mostDays, ?string $firstDay): CarbonImmutable
    {
        $earliest = $today->subDays(max(1, $mostDays) - 1);
        $first = self::day($firstDay);

        return match (true) {
            $first === null, $first->lessThan($earliest) => $earliest,
            $first->greaterThan($today) => $today,
            default => $first,
        };
    }

    /** A day typed as Y-m-d that is a real date, or null. */
    private static function day(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year) ? CarbonImmutable::create($year, $month, $day, 0, 0, 0, 'UTC') : null;
    }
}
