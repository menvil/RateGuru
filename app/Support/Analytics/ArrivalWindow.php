<?php

namespace App\Support\Analytics;

use Carbon\CarbonImmutable;

/**
 * A stretch of arrivals — from a moment, before another or for ever after —
 * as the whole UTC days within it and the part days at its ends: what the
 * sums of a game's days (analytics_game_days) can give, and what only the
 * visits themselves can. A stretch within one day, or two part days with no
 * whole one between, is a part day alone. Pure.
 */
final readonly class ArrivalWindow
{
    /**
     * @param  string|null  $fromDay  the first whole day, Y-m-d; none when the stretch holds no whole day
     * @param  string|null  $beforeDay  the day the whole days end before, Y-m-d; none when they never end
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $parts  the part days, each from a moment and before another
     */
    private function __construct(
        public ?string $fromDay,
        public ?string $beforeDay,
        public array $parts,
    ) {}

    public static function of(CarbonImmutable $since, ?CarbonImmutable $until = null): self
    {
        $since = $since->utc();
        $until = $until?->utc();
        $firstWhole = $since->equalTo($since->startOfDay()) ? $since : $since->startOfDay()->addDay();
        $endOfWhole = $until?->startOfDay();

        if ($until !== null && $endOfWhole !== null && $firstWhole->greaterThanOrEqualTo($endOfWhole)) {
            return new self(null, null, $since->lessThan($until) ? [[$since, $until]] : []);
        }

        $parts = [];

        if (! $firstWhole->equalTo($since)) {
            $parts[] = [$since, $firstWhole];
        }

        if ($until !== null && $endOfWhole !== null && ! $until->equalTo($endOfWhole)) {
            $parts[] = [$endOfWhole, $until];
        }

        return new self($firstWhole->toDateString(), $endOfWhole?->toDateString(), $parts);
    }

    /** Whether the stretch holds a whole day. */
    public function hasWholeDays(): bool
    {
        return $this->fromDay !== null;
    }
}
