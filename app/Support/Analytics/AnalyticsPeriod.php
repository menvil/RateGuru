<?php

namespace App\Support\Analytics;

use App\Support\Traffic\Reports\ReportPeriod;
use Carbon\CarbonImmutable;

/**
 * The days Growth → Analytics reads, today included: one of its presets, or
 * a range of whole days typed in, read as every report page reads its period
 * (ReportPeriod) — and, since Analytics reads the visits themselves with no
 * totals kept aside, never longer than the days it may read at a time. A
 * range that is longer is read like any range that cannot be: the preset
 * named instead, else the default. Days are network days: a visit's day in
 * its source's time zone, UTC for every unpaid visit.
 *
 * It also gives the window just before it, of the same length, to compare
 * with — none once that would start before the oldest day still kept; today
 * alone, not over yet, against the day before only as far into it as today
 * has gone — how the comparison and the days read, and the buckets a chart
 * splits it into: hours of a single day, those of today still to come said
 * so, days up to 31, else blocks of seven days from the first, the last
 * block as long as the days left. Now is given, never read from the clock.
 */
final readonly class AnalyticsPeriod
{
    public const string DEFAULT = '30d';

    /** @var array<string, string> the presets, by the key the address names them by */
    public const array PRESETS = [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        '90d' => 'Last 90 days',
        'this-month' => 'This month',
        'last-month' => 'Last month',
    ];

    public const string HOUR = 'hour';

    public const string DAY = 'day';

    public const string WEEK = 'week';

    /** The longest period a chart still draws day by day. */
    public const int DAILY_UP_TO = 31;

    public const int BLOCK_DAYS = 7;

    /**
     * @param  CarbonImmutable  $now  the moment it was read at
     * @param  CarbonImmutable|null  $arrivedBefore  the moment no visit arriving at or after counts; none but for the day before today, as far into it as today has gone
     */
    private function __construct(
        public string $preset,
        public string $fromDay,
        public string $toDay,
        public CarbonImmutable $today,
        public int $keptDays,
        public CarbonImmutable $now,
        public ?CarbonImmutable $arrivedBefore = null,
    ) {}

    /**
     * @param  CarbonImmutable  $now  the moment it is read at: its day is today
     * @param  int  $keptDays  how many days back, today included, the visits are kept
     * @param  int  $maxDays  the most days read at a time
     */
    public static function read(mixed $preset, mixed $from, mixed $to, CarbonImmutable $now, int $keptDays, int $maxDays): self
    {
        $now = $now->utc();
        $today = $now->startOfDay();
        $period = ReportPeriod::read(self::PRESETS, self::DEFAULT, $preset, $from, $to, $today, $keptDays);

        if ($period->isCustom() && self::length((string) $period->fromDay, (string) $period->toDay) > $maxDays) {
            $period = ReportPeriod::read(self::PRESETS, self::DEFAULT, $preset, null, null, $today, $keptDays);
        }

        if ($period->isCustom()) {
            return new self(ReportPeriod::CUSTOM, (string) $period->fromDay, (string) $period->toDay, $today, $keptDays, $now);
        }

        [$first, $last] = self::presetDays($period->preset, $today);

        return new self($period->preset, $first->toDateString(), $last->toDateString(), $today, $keptDays, $now);
    }

    public function isCustom(): bool
    {
        return $this->preset === ReportPeriod::CUSTOM;
    }

    /** What the period picker calls it: the preset's name, or a custom range. */
    public function label(): string
    {
        return self::PRESETS[$this->preset] ?? 'Custom range';
    }

    /** How many days it covers, the first and the last included. */
    public function days(): int
    {
        return self::length($this->fromDay, $this->toDay);
    }

    /** Its first moment: the first day's start, UTC. */
    public function start(): CarbonImmutable
    {
        return self::day($this->fromDay);
    }

    /** The moment after its last day. */
    public function end(): CarbonImmutable
    {
        return self::day($this->toDay)->addDay();
    }

    /** Its days as the page names them: “10 Sep — 09 Oct 2026”, one day alone when it is one. */
    public function range(): string
    {
        return ReportPeriod::span(self::day($this->fromDay), self::day($this->toDay));
    }

    /** Whether it is today alone: a day not over yet, its visits only those up to now. */
    public function isToday(): bool
    {
        return $this->fromDay === $this->toDay && $this->toDay === $this->today->toDateString();
    }

    /**
     * The window of the same length just before it: what its figures are
     * compared with — today alone with the day before as far into it as
     * today has gone, so the hours still to come today don't count against
     * it. None when it would start before the oldest day kept.
     */
    public function previous(): ?self
    {
        $days = $this->days();
        $first = self::day($this->fromDay)->subDays($days);

        if ($first->lessThan($this->today->subDays($this->keptDays - 1))) {
            return null;
        }

        return new self(ReportPeriod::CUSTOM, $first->toDateString(), self::day($this->fromDay)->subDay()->toDateString(), $this->today, $this->keptDays, $this->now, $this->isToday() ? $this->now->subDay() : null);
    }

    /** What a figure is compared with, as a change reads it: “yesterday by this time”, “the day before”, “the previous 30 days”. */
    public function comparison(): string
    {
        $days = $this->days();

        return match (true) {
            $this->isToday() => 'yesterday by this time',
            $days === 1 => 'the day before',
            default => 'the previous '.$days.' days',
        };
    }

    /** The unit a chart of it counts by: hours of a single day, days up to 31, else blocks of seven days. */
    public function unit(): string
    {
        $days = $this->days();

        return match (true) {
            $days === 1 => self::HOUR,
            $days <= self::DAILY_UP_TO => self::DAY,
            default => self::WEEK,
        };
    }

    /**
     * The buckets a chart of it splits into, in order: each with its label
     * and the hint its column shows, the network days it covers, its first
     * moment and the one after its last, how many days it holds — every
     * block seven but the last, which holds the days left and says so — and
     * whether it is still to come: an hour of today not yet begun.
     *
     * @return list<array{unit: string, label: string, hint: string, fromDay: string, toDay: string, from: CarbonImmutable, before: CarbonImmutable, days: int, toCome: bool}>
     */
    public function buckets(): array
    {
        $first = self::day($this->fromDay);
        $last = self::day($this->toDay);

        return match ($this->unit()) {
            self::HOUR => array_map(fn (int $hour): array => [
                'unit' => self::HOUR,
                'label' => sprintf('%02d:00', $hour),
                'hint' => $first->format('d M Y').' · '.sprintf('%02d:00–%02d:00', $hour, $hour + 1).' UTC',
                'fromDay' => $this->fromDay,
                'toDay' => $this->toDay,
                'from' => $first->addHours($hour),
                'before' => $first->addHours($hour + 1),
                'days' => 1,
                'toCome' => $first->addHours($hour)->greaterThan($this->now),
            ], range(0, 23)),
            self::DAY => array_map(fn (int $offset): array => self::bucket(self::DAY, $first->addDays($offset), $first->addDays($offset)), range(0, $this->days() - 1)),
            default => array_map(function (int $offset) use ($first, $last): array {
                $blockEnd = $first->addDays($offset + self::BLOCK_DAYS - 1);

                return self::bucket(self::WEEK, $first->addDays($offset), $blockEnd->lessThan($last) ? $blockEnd : $last);
            }, range(0, $this->days() - 1, self::BLOCK_DAYS)),
        };
    }

    /** @return array{unit: string, label: string, hint: string, fromDay: string, toDay: string, from: CarbonImmutable, before: CarbonImmutable, days: int, toCome: bool} */
    private static function bucket(string $unit, CarbonImmutable $first, CarbonImmutable $last): array
    {
        $days = self::length($first->toDateString(), $last->toDateString());

        return [
            'unit' => $unit,
            'label' => $unit === self::DAY ? $first->format('d M') : 'Week of '.$first->format('d M'),
            'hint' => $unit === self::DAY
                ? $first->format('D d M Y')
                : ReportPeriod::span($first, $last).($days < self::BLOCK_DAYS ? ' · '.$days.($days === 1 ? ' day' : ' days') : ''),
            'fromDay' => $first->toDateString(),
            'toDay' => $last->toDateString(),
            'from' => $first,
            'before' => $last->addDay(),
            'days' => $days,
            // A period's days run up to today at the latest.
            'toCome' => false,
        ];
    }

    /**
     * A preset's first and last day: today or yesterday alone; the last 7,
     * 30 or 90 days up to today; this month up to today; or the whole of
     * last month.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function presetDays(string $preset, CarbonImmutable $today): array
    {
        return match ($preset) {
            'today' => [$today, $today],
            'yesterday' => [$today->subDay(), $today->subDay()],
            '7d' => [$today->subDays(6), $today],
            '90d' => [$today->subDays(89), $today],
            'this-month' => [$today->startOfMonth(), $today],
            'last-month' => [$today->startOfMonth()->subMonthNoOverflow(), $today->startOfMonth()->subDay()],
            default => [$today->subDays(29), $today],
        };
    }

    private static function length(string $fromDay, string $toDay): int
    {
        return (int) self::day($fromDay)->diffInDays(self::day($toDay), true) + 1;
    }

    private static function day(string $day): CarbonImmutable
    {
        return CarbonImmutable::parse($day, 'UTC')->startOfDay();
    }
}
