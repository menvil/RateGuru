<?php

namespace App\Support\Analytics;

/**
 * How a figure moved against the window just before its period: up or down
 * by how many percent of what it was — “+18% vs the previous 30 days”,
 * “−4% vs the day before” — the same when it moved by less than half a
 * percent, and no earlier data when the window before counted none. There
 * is no change at all without a window before (AnalyticsPeriod::previous()).
 */
final readonly class FigureChange
{
    public const string UP = 'up';

    public const string DOWN = 'down';

    public const string SAME = 'same';

    public const string NO_EARLIER_DATA = 'none';

    private function __construct(
        public string $text,
        public string $direction,
    ) {}

    /**
     * @param  string|null  $comparison  what the window before is called (AnalyticsPeriod::comparison()); null without one
     */
    public static function between(int|float $now, int|float|null $before, ?string $comparison): ?self
    {
        if ($comparison === null || $before === null) {
            return null;
        }

        if ($before <= 0) {
            return new self('No earlier data', self::NO_EARLIER_DATA);
        }

        $moved = ($now - $before) * 100 / $before;

        if (abs($moved) < 0.5) {
            return new self('Same as '.$comparison, self::SAME);
        }

        return new self(($moved > 0 ? '+' : '−').number_format(abs($moved), 0, '.', ',').'% vs '.$comparison, $moved > 0 ? self::UP : self::DOWN);
    }
}
