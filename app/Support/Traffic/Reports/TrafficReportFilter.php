<?php

namespace App\Support\Traffic\Reports;

use App\Enums\TrafficDimension;
use Carbon\CarbonImmutable;

/**
 * Which visits a report counts: the network days of a period — each visit's
 * day in its source's time zone, so the figures match the network's own —
 * and the path opened in the tree, each step a dimension with its value.
 */
final readonly class TrafficReportFilter
{
    /** @param  array<string, int|string|null>  $where  by visit column, from TrafficDimension::columns() only */
    public function __construct(
        public string $fromDay,
        public string $toDay,
        public array $where = [],
    ) {}

    public static function lastDays(int $days, CarbonImmutable $now): self
    {
        $today = $now->utc()->startOfDay();

        return new self($today->subDays($days - 1)->toDateString(), $today->toDateString());
    }

    /** The same period, one step further down the tree. */
    public function within(TrafficDimension $dimension, array $values): self
    {
        $where = $this->where;

        foreach ($dimension->columns() as $index => $column) {
            if (array_key_exists($column, $values) || $index === 0) {
                $where[$column] = $values[$column] ?? null;
            }
        }

        return new self($this->fromDay, $this->toDay, $where);
    }

    /** A wider span of arrivals than the days, so the arrival index narrows the scan first. */
    public function arrivedFrom(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->fromDay, 'UTC')->subHours(14);
    }

    public function arrivedBefore(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->toDay, 'UTC')->addHours(36);
    }
}
