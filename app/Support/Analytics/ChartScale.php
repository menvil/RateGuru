<?php

namespace App\Support\Analytics;

/**
 * A bar chart's scale: lines at round values — 1, 2 or 5 times a power of
 * ten apart — no more than four of them, the top one at or just above the
 * busiest bar, so a reader sees how many the bars stand for without pointing
 * at one. A chart of nobody draws none. Pure: no config, clock or I/O.
 */
final readonly class ChartScale
{
    public const MOST_LINES = 4;

    /** @param  list<int>  $lines  the values the lines stand at, lowest first */
    private function __construct(public int $top, public array $lines) {}

    public static function of(int $busiest): self
    {
        if ($busiest <= 0) {
            return new self(1, []);
        }

        for ($power = 1; ; $power *= 10) {
            foreach ([1, 2, 5] as $times) {
                $step = $times * $power;
                $count = intdiv($busiest + $step - 1, $step);

                if ($count <= self::MOST_LINES) {
                    return new self($count * $step, array_map(fn (int $n): int => $n * $step, range(1, $count)));
                }
            }
        }
    }

    /** How high a count stands, in percent of the chart, to a tenth. */
    public function height(int $count): float
    {
        return round(max(0, $count) / $this->top * 100, 1);
    }
}
