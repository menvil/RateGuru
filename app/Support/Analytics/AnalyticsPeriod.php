<?php

namespace App\Support\Analytics;

use App\Support\Traffic\Reports\ReportPeriod;
use Carbon\CarbonImmutable;
use Closure;

/**
 * The days Growth → Analytics reads, today included: one of its presets, or
 * a range of whole days typed in, read as every report page reads its period
 * (ReportPeriod) — and, since Analytics reads the visits themselves with no
 * totals kept aside, never longer than the days it may read at a time. A
 * range that is longer is read like any range that cannot be: the preset
 * named instead, else the default. All time is every day since the first
 * visit, up to those most days: the last of them once visits go back
 * further. Days are network days: a visit's day in its source's time zone,
 * UTC for every unpaid visit.
 *
 * It also gives the window before it to compare with — of the same length,
 * just before; this month's days with the same days of last month, and last
 * month with the whole month before; none for all time, nor once that would
 * start before the oldest day still kept; today alone, not over yet, against
 * the people who arrived the day before only as far into it as today has
 * gone, whatever they did after — how the comparison
 * and the days read, and the buckets a chart splits it into: hours of a
 * single day, those of today still to come said so, days up to 31, else
 * blocks of seven days from the first, the last block as long as the days
 * left. Now is given, never read from the clock.
 */
final readonly class AnalyticsPeriod
{
    public const string DEFAULT = '30d';

    /** @var array<string, string> the presets, by the key the address names them by: every report page's */
    public const array PRESETS = ReportPeriod::PRESETS;

    public const string HOUR = 'hour';

    public const string DAY = 'day';

    public const string WEEK = 'week';

    /** The longest period a chart still draws day by day. */
    public const int DAILY_UP_TO = 31;

    public const int BLOCK_DAYS = 7;

    /**
     * @param  CarbonImmutable  $now  the moment it was read at
     * @param  CarbonImmutable|null  $arrivedBefore  the moment no visit arriving at or after counts — a visit arriving before it counts with all it did later; none but for the day before today, as far into it as today has gone
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
     * @param  (Closure(): ?string)|null  $firstDay  the network day of the first visit, Y-m-d, or null for none yet: asked for all time alone
     */
    public static function read(mixed $preset, mixed $from, mixed $to, CarbonImmutable $now, int $keptDays, int $maxDays, ?Closure $firstDay = null): self
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

        [$first, $last] = ReportPeriod::presetDays($period->preset, $today, min($maxDays, $keptDays), $firstDay);

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
     * The window before it, what its figures are compared with: the days of
     * the same length just before it; this month's days with the same days
     * of last month, as many as last month has; last month with the whole
     * month before. Today alone — this month on its first day too — is
     * compared with the people who arrived the day before as far into it as
     * today has gone, so the hours still to come today don't count against
     * it: that moment bounds the arrivals, and what each of those people did
     * counts whenever they did it, as in every window. None for all time,
     * which has nothing earlier, nor when it would start before the oldest
     * day kept.
     */
    public function previous(): ?self
    {
        if ($this->preset === ReportPeriod::ALL) {
            return null;
        }

        $start = self::day($this->fromDay);
        [$first, $last] = match (true) {
            $this->isToday() => [$start->subDay(), $start->subDay()],
            $this->preset === 'this-month' => [$start->subMonthNoOverflow(), self::earlier($start->subMonthNoOverflow()->addDays($this->days() - 1), $start->subDay())],
            $this->preset === 'last-month' => [$start->subMonthNoOverflow(), $start->subDay()],
            default => [$start->subDays($this->days()), $start->subDay()],
        };

        if ($first->lessThan($this->today->subDays($this->keptDays - 1))) {
            return null;
        }

        return new self(ReportPeriod::CUSTOM, $first->toDateString(), $last->toDateString(), $this->today, $this->keptDays, $this->now, $this->isToday() ? $this->now->subDay() : null);
    }

    /**
     * What a figure is compared with, as a change reads it: “people who
     * arrived yesterday by this time”, “the day before”, “the previous 30
     * days”, “the same days of last month”, “the month before”. Today's
     * window before stops the arrivals at this time of day, not what those
     * people did after it — every figure here counts the people who arrived
     * in a window, whenever they went on — so it names the arrivals.
     */
    public function comparison(): string
    {
        $days = $this->days();

        return match (true) {
            $this->isToday() => 'people who arrived yesterday by this time',
            $this->preset === 'this-month' => 'the same days of last month',
            $this->preset === 'last-month' => 'the month before',
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

    private static function earlier(CarbonImmutable $one, CarbonImmutable $other): CarbonImmutable
    {
        return $one->lessThan($other) ? $one : $other;
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
