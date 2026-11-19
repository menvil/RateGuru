<?php

namespace App\Filament\Resources\Games\Analytics;

use App\Support\Traffic\Reports\ReportPeriod;
use Carbon\CarbonImmutable;

/**
 * The window a game's analytics reads (GameAnalyticsView): from its first
 * moment up to the one after its last — whole days, up to now where they
 * reach today — and the window of the same length right before it, to
 * compare with, unless those days are no longer kept or the window is all
 * time. It names itself as the notes say it (“last 30 days”, or its days)
 * and carries the period the links to Traffic sources open with. Now is
 * given, never read from the clock.
 */
final readonly class GameAnalyticsWindow
{
    /**
     * @param  CarbonImmutable|null  $before  the window before's first moment; none for all time, or once those days are gone
     * @param  array<string, string|null>  $links  the period the links to Traffic sources carry
     */
    private function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $until,
        public ?CarbonImmutable $before,
        public bool $beforeGone,
        public bool $allTime,
        public string $name,
        public array $links,
    ) {}

    /**
     * Whole days, from the first one's start to the moment after the last —
     * up to now where that is later — against as long a time right before.
     *
     * @param  string|null  $name  what the notes call the window; its days for none
     * @param  int  $keptDays  how many days back the figures are kept: the window before is dropped once it starts earlier
     * @param  array<string, string|null>  $links
     */
    public static function days(CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $now, int $keptDays, ?string $name, array $links): self
    {
        $until = $end->lessThan($now) ? $end : $now;
        $before = $start->subSeconds((int) $start->diffInSeconds($until));
        // The days before are compared only while they are still kept.
        $gone = $before->lessThan($now->subDays($keptDays));

        return new self($start, $until, $gone ? null : $before, $gone, false, $name ?? self::span($start, $until), $links);
    }

    /**
     * Everything up to now, with nothing earlier to compare with.
     *
     * @param  array<string, string|null>  $links
     */
    public static function allTime(CarbonImmutable $now, array $links): self
    {
        return new self(CarbonImmutable::createFromTimestampUTC(0), $now, null, false, true, 'all time', $links);
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
            $this->before !== null => $this->dates().' · compared with '.self::span($this->before, $this->from),
            $this->beforeGone => $this->dates().' · the days before are no longer kept, nothing to compare with',
            default => 'All time · nothing earlier to compare with',
        };
    }

    /** A window's days, from its first moment up to the one after its last. */
    private static function span(CarbonImmutable $from, CarbonImmutable $until): string
    {
        return ReportPeriod::span($from, $until->subSecond());
    }
}
