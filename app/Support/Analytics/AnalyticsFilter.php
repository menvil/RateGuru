<?php

namespace App\Support\Analytics;

use App\Enums\AnalyticsDimension;
use App\Enums\TrafficDevice;
use App\Support\Traffic\Reports\TrafficReportFilter;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Which visits an Analytics figure counts: those of the period's network
 * days — only those arrived before the moment it stops at, when it has one
 * — of one device or either, from one source and one campaign or any —
 * both together when both are chosen — and along the path a breakdown has
 * opened, each step a dimension with its value. Every column it names comes
 * from AnalyticsDimension; a path naming any other is refused.
 */
final readonly class AnalyticsFilter
{
    /**
     * @param  string|null  $sourceKey  a session's source (AnalyticsSource)
     * @param  string|null  $campaignKey  a session's campaign (CampaignKey)
     * @param  array<string, int|string|null>  $where  the path opened, by visit column, from AnalyticsDimension::columns() only
     */
    public function __construct(
        public AnalyticsPeriod $period,
        public ?TrafficDevice $device = null,
        public ?string $sourceKey = null,
        public ?string $campaignKey = null,
        public array $where = [],
    ) {
        foreach (array_keys($where) as $column) {
            if (! AnalyticsDimension::reads($column)) {
                throw new InvalidArgumentException("No Analytics dimension reads the column {$column}.");
            }
        }
    }

    /**
     * The same visits, one step further down a breakdown.
     *
     * @param  array<string, int|string|null>  $values  by the dimension's columns
     */
    public function within(AnalyticsDimension $dimension, array $values): self
    {
        $where = $this->where;

        foreach ($dimension->columns() as $index => $column) {
            if (array_key_exists($column, $values) || $index === 0) {
                $where[$column] = $values[$column] ?? null;
            }
        }

        return new self($this->period, $this->device, $this->sourceKey, $this->campaignKey, $where);
    }

    /** The same filter over the window just before the period; none when that window is no longer kept. */
    public function previous(): ?self
    {
        $previous = $this->period->previous();

        return $previous === null ? null : new self($previous, $this->device, $this->sourceKey, $this->campaignKey, $this->where);
    }

    /** The period and the device alone: what a table that reads every source and campaign counts. */
    public function periodAndDevice(): self
    {
        return new self($this->period, $this->device);
    }

    /** Whether a source or a campaign is chosen. */
    public function narrowsSource(): bool
    {
        return $this->sourceKey !== null || $this->campaignKey !== null;
    }

    public function fromDay(): string
    {
        return $this->period->fromDay;
    }

    public function toDay(): string
    {
        return $this->period->toDay;
    }

    /** A wider span of arrivals than the days, so the arrival index narrows the scan first — as every traffic report does. */
    public function arrivedFrom(): CarbonImmutable
    {
        return $this->window()->arrivedFrom();
    }

    /** Whether the period counts its days whole: no moment stops it counting arrivals sooner than its last day's end. */
    public function countsWholeDays(): bool
    {
        return $this->arrivedBefore()->equalTo($this->window()->arrivedBefore());
    }

    /** The end of that span — or, sooner, the moment the period stops counting arrivals at (the day before today, as far into it as today has gone). */
    public function arrivedBefore(): CarbonImmutable
    {
        $before = $this->window()->arrivedBefore();
        $cutoff = $this->period->arrivedBefore;

        return $cutoff !== null && $cutoff->lessThan($before) ? $cutoff : $before;
    }

    /**
     * Every condition on a visit column besides the days, each to hold —
     * the device, the source, the campaign, then the path — a column with
     * its value, null for none.
     *
     * @return list<array{0: string, 1: int|string|null}>
     */
    public function conditions(): array
    {
        $conditions = [];

        foreach ([
            [AnalyticsDimension::Device, $this->device?->value],
            [AnalyticsDimension::Source, $this->sourceKey],
            [AnalyticsDimension::Campaign, $this->campaignKey],
        ] as [$dimension, $value]) {
            if ($value !== null) {
                $conditions[] = [$dimension->columns()[0], $value];
            }
        }

        foreach ($this->where as $column => $value) {
            $conditions[] = [$column, $value];
        }

        return $conditions;
    }

    private function window(): TrafficReportFilter
    {
        return new TrafficReportFilter($this->period->fromDay, $this->period->toDay);
    }
}
