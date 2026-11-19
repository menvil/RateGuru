<?php

namespace App\Filament\Resources\Games\Analytics;

use App\Support\Traffic\Reports\ReportPeriod;
use Carbon\CarbonImmutable;

/**
 * The window a game's analytics reads (GameAnalyticsView): from its first
 * moment up to the one after its last — whole days, up to now where they
 * reach today — and the window before it, to compare with: as long a time
 * right before it; for this month, as far into last month as this month has
 * gone, and for last month the whole month before; none for all time, nor
 * once those days are no longer kept. Today alone, not over yet, is compared
 * with the day before only as far into it as today has gone — the same hours
 * of the day, as Growth → Analytics compares it. It names itself as the
 * notes say it (“last 30 days”, or its days) and carries the period the
 * links to Traffic sources open with. Now is given, never read from the
 * clock.
 */
final readonly class GameAnalyticsWindow
{
    /** Compared with as long a time right before it. */
    public const string SAME_LENGTH = 'length';

    /** Compared with as far into the month before as it runs into its own month: the whole month before, for a whole month. */
    public const string MONTH_BEFORE = 'month';

    /** Compared with nothing: all the time there is. */
    public const string NOTHING = 'nothing';

    /**
     * @param  CarbonImmutable|null  $before  the window before's first moment; none for all time, or once those days are gone
     * @param  CarbonImmutable|null  $beforeUntil  the moment after the window before's last: the window's own first, or — today alone, or part of a month — as far into the time before as the window has gone; none with no window before
     * @param  array<string, string|null>  $links  the period the links to Traffic sources carry
     */
    private function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $until,
        public ?CarbonImmutable $before,
        public ?CarbonImmutable $beforeUntil,
        public bool $beforeGone,
        public bool $allTime,
        public string $name,
        public array $links,
    ) {}

    /**
     * Whole days, from the first one's start to the moment after the last —
     * up to now where that is later — against the time before it the
     * comparison says (SAME_LENGTH, MONTH_BEFORE or NOTHING); today alone
     * against the day before up to the same time of day.
     *
     * @param  string|null  $name  what the notes call the window; its days for none
     * @param  int  $keptDays  how many days back the figures are kept: the window before is dropped once it starts earlier
     * @param  array<string, string|null>  $links
     */
    public static function days(CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $now, int $keptDays, ?string $name, array $links, string $comparison = self::SAME_LENGTH): self
    {
        $until = $end->lessThan($now) ? $end : $now;
        $name ??= self::span($start, $until);

        if ($comparison === self::NOTHING) {
            return new self($start, $until, null, null, false, false, $name, $links);
        }

        // Today alone stops at now: the day before counts only as far into it, the rest of today being still to come.
        $today = $until->equalTo($now) && $start->greaterThanOrEqualTo($now->subSecond()->startOfDay());
        [$before, $beforeUntil] = match (true) {
            $today => [$start->subDay(), $until->subDay()],
            $comparison === self::MONTH_BEFORE => self::monthBefore($start, $until),
            default => [$start->subSeconds((int) $start->diffInSeconds($until)), $start],
        };
        // The days before are compared only while they are still kept.
        $gone = $before->lessThan($now->subDays($keptDays));

        return new self($start, $until, $gone ? null : $before, $gone ? null : $beforeUntil, $gone, false, $name, $links);
    }

    /**
     * Everything up to now, with nothing earlier to compare with.
     *
     * @param  array<string, string|null>  $links
     */
    public static function allTime(CarbonImmutable $now, array $links): self
    {
        return new self(CarbonImmutable::createFromTimestampUTC(0), $now, null, null, false, true, 'all time', $links);
    }

    /** What a preset of the report pages' (ReportPeriod::PRESETS) is compared with: this month and last month with the month before, all time with nothing, any other with as long a time right before it. */
    public static function comparisonOf(string $preset): string
    {
        return match ($preset) {
            'this-month', 'last-month' => self::MONTH_BEFORE,
            ReportPeriod::ALL => self::NOTHING,
            default => self::SAME_LENGTH,
        };
    }

    /** Its days as the filter bar names them: “20 Oct — 18 Nov 2026”. */
    public function dates(): string
    {
        return self::span($this->from, $this->until);
    }

    /** What the filter bar says the figures are of, and what they are compared with. */
    public function text(): string
    {
        return match (true) {
            $this->before !== null && $this->beforeUntil !== null => $this->dates().' · compared with '.self::span($this->before, $this->beforeUntil).($this->beforeUntil->lessThan($this->from) ? ' up to the same time' : ''),
            $this->beforeGone => $this->dates().' · the days before are no longer kept, nothing to compare with',
            $this->allTime => 'All time · nothing earlier to compare with',
            default => $this->dates().' · nothing earlier to compare with',
        };
    }

    /**
     * The month before a window that starts on a month's first day: from
     * that month's first moment as far into it as the window runs into its
     * own — the whole month before once the window reaches its month's end,
     * or runs past the days the month before has.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function monthBefore(CarbonImmutable $start, CarbonImmutable $until): array
    {
        $before = $start->subMonthNoOverflow();
        $asFar = $before->addSeconds((int) $start->diffInSeconds($until));

        return [$before, $until->greaterThanOrEqualTo($start->addMonthNoOverflow()) || $asFar->greaterThan($start) ? $start : $asFar];
    }

    /** A window's days, from its first moment up to the one after its last. */
    private static function span(CarbonImmutable $from, CarbonImmutable $until): string
    {
        return ReportPeriod::span($from, $until->subSecond());
    }
}
