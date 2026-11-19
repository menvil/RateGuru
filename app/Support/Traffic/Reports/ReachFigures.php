<?php

namespace App\Support\Traffic\Reports;

use App\Support\Traffic\Money\TrafficMoney;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The impressions a network billed for and how many of them reached
 * RateGuru (docs/architecture/traffic.md, "Reach"): the network's paid
 * impressions and its amount for them, and the visits of the same slices —
 * how many came, opened the page and started the game. Added up raw, so a
 * sum stays honest when one slice's visits outran its impressions — two
 * clocks never cut a day at quite the same moment — and only what is shown
 * is held to sense: never reached no lower than nothing, reached no more
 * than every impression.
 */
final readonly class ReachFigures
{
    public function __construct(
        public int $impressions = 0,
        public string $amount = '0',
        public int $reached = 0,
        public int $opened = 0,
        public int $started = 0,
    ) {}

    public function plus(self $other): self
    {
        return new self(
            $this->impressions + $other->impressions,
            TrafficMoney::stored(TrafficMoney::of($this->amount)->plus(TrafficMoney::of($other->amount))),
            $this->reached + $other->reached,
            $this->opened + $other->opened,
            $this->started + $other->started,
        );
    }

    /** The paid impressions that never became a visit, as shown: never below nothing. */
    public function neverReached(): int
    {
        return max(0, $this->impressions - $this->reached);
    }

    /** The share of the paid impressions that became a visit, 0–1; null with none paid. */
    public function reachRate(): ?float
    {
        return $this->impressions === 0 ? null : min($this->reached, $this->impressions) / $this->impressions;
    }

    /** The share of the paid impressions that never became a visit, 0–1; null with none paid. */
    public function neverReachedRate(): ?float
    {
        return $this->impressions === 0 ? null : $this->neverReached() / $this->impressions;
    }

    /**
     * What the impressions that never arrived cost, as an estimate: the
     * network's amount shared evenly over its impressions — a network bills
     * each at the bid of its moment, which no report of a day breaks down.
     */
    public function lostAmount(): BigDecimal
    {
        return $this->impressions === 0
            ? BigDecimal::zero()
            : TrafficMoney::of($this->amount)->multipliedBy($this->neverReached())->dividedBy($this->impressions, TrafficMoney::SCALE, RoundingMode::HalfUp);
    }

    /**
     * Whether so many paid impressions reached RateGuru far less often than
     * those of the whole source over the same days: at least the impressions
     * asked for, and a share reached below the given percentage of the
     * source's. Compared in whole numbers, so a share right on the line is
     * never below it.
     */
    public function fallsShortOf(self $source, int $minImpressions, int $percentOfSource): bool
    {
        if ($this->impressions < max(1, $minImpressions) || $source->impressions === 0) {
            return false;
        }

        return min($this->reached, $this->impressions) * $source->impressions * 100
            < min($source->reached, $source->impressions) * $this->impressions * $percentOfSource;
    }
}
