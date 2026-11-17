<?php

namespace App\Support\Traffic\Reports;

use App\Support\Traffic\Money\TrafficMoney;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The figures of one row of a traffic report — visits, how far they got,
 * the accounts they brought, what they cost and what they are worth — read
 * the same whatever the engine returned, added up, and turned into the
 * rates the screens show. New visitors are the visits less the known ones:
 * an account can only come from them.
 */
final readonly class ReportFigures
{
    public function __construct(
        public int $visits = 0,
        public int $opened = 0,
        public int $started = 0,
        public int $completed = 0,
        public int $goal = 0,
        public int $accounts = 0,
        public int $posted = 0,
        public int $returned = 0,
        public int $knownOpened = 0,
        public string $spend = '0',
        public string $spendEstimated = '0',
        public string $knownSpend = '0',
        public string $valueReal = '0',
        public string $valueExpected = '0',
    ) {}

    /** @param  array<string, mixed>|object  $row */
    public static function fromRow(array|object $row): self
    {
        $row = (array) $row;

        return new self(
            (int) ($row['visits'] ?? 0),
            (int) ($row['opened'] ?? 0),
            (int) ($row['started'] ?? 0),
            (int) ($row['completed'] ?? 0),
            (int) ($row['goal'] ?? 0),
            (int) ($row['accounts'] ?? 0),
            (int) ($row['posted'] ?? 0),
            (int) ($row['returned'] ?? 0),
            (int) ($row['known_opened'] ?? 0),
            TrafficMoney::stored($row['spend'] ?? 0),
            TrafficMoney::stored($row['spend_estimated'] ?? 0),
            TrafficMoney::stored($row['known_spend'] ?? 0),
            TrafficMoney::stored($row['value_real'] ?? 0),
            TrafficMoney::stored($row['value_expected'] ?? 0),
        );
    }

    public function plus(self $other): self
    {
        $sum = fn (string $a, string $b): string => TrafficMoney::stored(TrafficMoney::of($a)->plus(TrafficMoney::of($b)));

        return new self(
            $this->visits + $other->visits,
            $this->opened + $other->opened,
            $this->started + $other->started,
            $this->completed + $other->completed,
            $this->goal + $other->goal,
            $this->accounts + $other->accounts,
            $this->posted + $other->posted,
            $this->returned + $other->returned,
            $this->knownOpened + $other->knownOpened,
            $sum($this->spend, $other->spend),
            $sum($this->spendEstimated, $other->spendEstimated),
            $sum($this->knownSpend, $other->knownSpend),
            $sum($this->valueReal, $other->valueReal),
            $sum($this->valueExpected, $other->valueExpected),
        );
    }

    /** Spend with no visit to share it over, added to the row it belongs to. */
    public function withSpend(string $extra): self
    {
        return new self($this->visits, $this->opened, $this->started, $this->completed, $this->goal, $this->accounts, $this->posted, $this->returned, $this->knownOpened,
            TrafficMoney::stored(TrafficMoney::of($this->spend)->plus(TrafficMoney::of($extra))), $this->spendEstimated, $this->knownSpend, $this->valueReal, $this->valueExpected);
    }

    public function newVisitors(): int
    {
        return max(0, $this->opened - $this->knownOpened);
    }

    public function value(): BigDecimal
    {
        return TrafficMoney::of($this->valueReal)->plus(TrafficMoney::of($this->valueExpected));
    }

    /** Value less spend, over spend, in percent; null without spend. */
    public function roi(): ?float
    {
        $spend = TrafficMoney::of($this->spend);

        return $spend->isZero() ? null : (float) (string) $this->value()->minus($spend)->multipliedBy(100)->dividedBy($spend, 4, RoundingMode::HalfUp);
    }

    /** What an account cost; null without accounts. */
    public function costPerAccount(): ?string
    {
        return $this->accounts === 0 ? null : (string) TrafficMoney::of($this->spend)->dividedBy($this->accounts, 4, RoundingMode::HalfUp);
    }

    public function openRate(): ?float
    {
        return $this->visits === 0 ? null : $this->opened / $this->visits;
    }

    public function startRate(): ?float
    {
        return $this->opened === 0 ? null : $this->started / $this->opened;
    }

    public function accountRate(): ?float
    {
        return $this->newVisitors() === 0 ? null : $this->accounts / $this->newVisitors();
    }
}
