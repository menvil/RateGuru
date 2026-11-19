<?php

namespace App\Support\Traffic\Worth;

use App\Support\Traffic\Money\TrafficMoney;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * What a confirmed account is worth to the site, from the prices of a set:
 * the account itself, plus its first post and its return on another day by
 * how often accounts do them, times the share its country group is worth;
 * and what a sign-in to an account the visitor already had is worth, its own
 * price times that share. A paid source can have an account price of its
 * own in the set; the prices for a source (forSource) use it in place of the
 * site's, and those for every source at once (mixed) weigh it in by how many
 * of the accounts came from that source. The bonuses and the group's share
 * apply on top of whichever account price it is.
 */
final readonly class WorthPrices
{
    /**
     * @param  string  $accountPrice  the site's account price, or the one these prices were resolved to
     * @param  array<int, string>  $sourceAccountPrices  the account price of each paid source that has its own, by source id; empty once resolved
     */
    public function __construct(
        public string $accountPrice,
        public string $postBonus,
        public string $returnedBonus,
        public string $loginPrice,
        public array $sourceAccountPrices = [],
    ) {}

    /** The account price a visit from this source counts: its own, else the site's — always the site's for unpaid traffic, which has no source. */
    public function accountPriceOf(?int $sourceId): string
    {
        return $sourceId !== null && isset($this->sourceAccountPrices[$sourceId]) ? $this->sourceAccountPrices[$sourceId] : $this->accountPrice;
    }

    /** Whether the set gives this source an account price of its own. */
    public function hasOwnAccountPrice(?int $sourceId): bool
    {
        return $sourceId !== null && isset($this->sourceAccountPrices[$sourceId]);
    }

    /**
     * The prices as a visit from this source is worth with them: its own
     * account price in place of the site's when it has one. Resolved prices
     * name no source prices any more, so resolving them again keeps them.
     */
    public function forSource(?int $sourceId): self
    {
        return new self($this->accountPriceOf($sourceId), $this->postBonus, $this->returnedBonus, $this->loginPrice);
    }

    /**
     * The prices for traffic of every source at once: the account price of
     * each source with its own weighed in by its share of the accounts, every
     * other account — unpaid traffic's among them — at the site price. With no
     * accounts to weigh, the site price.
     *
     * @param  array<int, int>  $accountsBySource  the accounts that came from each source with its own price, by source id
     * @param  int  $accounts  every account, from any source or none
     */
    public function mixed(array $accountsBySource, int $accounts): self
    {
        if ($accounts <= 0 || $this->sourceAccountPrices === []) {
            return $this->forSource(null);
        }

        $site = TrafficMoney::of($this->accountPrice);
        $sum = $site->multipliedBy($accounts);

        foreach ($this->sourceAccountPrices as $sourceId => $price) {
            $sum = $sum->plus(TrafficMoney::of($price)->minus($site)->multipliedBy(max(0, min($accounts, $accountsBySource[$sourceId] ?? 0))));
        }

        return new self((string) $sum->dividedBy($accounts, TrafficMoney::SCALE, RoundingMode::HalfUp), $this->postBonus, $this->returnedBonus, $this->loginPrice);
    }

    /** A: what an account from a group of this percent is expected to be worth. */
    public function account(float $postRate, float $returnRate, int $percent): BigDecimal
    {
        return TrafficMoney::of($this->accountPrice)
            ->plus(TrafficMoney::of($this->postBonus)->multipliedBy(self::rate($postRate)))
            ->plus(TrafficMoney::of($this->returnedBonus)->multipliedBy(self::rate($returnRate)))
            ->multipliedBy($percent)
            ->dividedBy(100, TrafficMoney::SCALE, RoundingMode::HalfUp);
    }

    /** What an account brought: the price, and each bonus it earned, times its group's share. */
    public function earned(bool $posted, bool $returned, int $percent): BigDecimal
    {
        $sum = TrafficMoney::of($this->accountPrice);
        $sum = $posted ? $sum->plus(TrafficMoney::of($this->postBonus)) : $sum;
        $sum = $returned ? $sum->plus(TrafficMoney::of($this->returnedBonus)) : $sum;

        return $sum->multipliedBy($percent)->dividedBy(100, TrafficMoney::SCALE, RoundingMode::HalfUp);
    }

    /** What a sign-in to an existing account from a group of this percent is worth. */
    public function login(int $percent): BigDecimal
    {
        return TrafficMoney::of($this->loginPrice)->multipliedBy($percent)->dividedBy(100, TrafficMoney::SCALE, RoundingMode::HalfUp);
    }

    private static function rate(float $rate): BigDecimal
    {
        return BigDecimal::of(number_format(max(0.0, min(1.0, $rate)), 8, '.', ''));
    }
}
