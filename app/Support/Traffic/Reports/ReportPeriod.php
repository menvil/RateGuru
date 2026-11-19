<?php

namespace App\Support\Traffic\Reports;

use Carbon\CarbonImmutable;

/**
 * The period a report page reads, as its address names it: one of the page's
 * presets, or a range of whole days typed into the period picker. A range is
 * read only when both its days are real dates, the first not after the last,
 * the last not after today and the first not before the oldest day still
 * kept — so never longer than the data is kept. Anything else, the address's
 * or a forged update's, is the page's default preset instead, never an
 * error. Today is given, never read from the clock.
 */
final readonly class ReportPeriod
{
    /** What a range of days typed in is called where a preset would be named. */
    public const string CUSTOM = 'custom';

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
