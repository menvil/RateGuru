<?php

namespace App\Support\Traffic\Worth;

use App\Support\Traffic\Money\TrafficMoney;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * What a confirmed account is worth to the site, from the prices of a set:
 * the account itself, plus its first post and its return on another day by
 * how often accounts do them, times the share its country group is worth.
 */
final readonly class WorthPrices
{
    public function __construct(
        public string $accountPrice,
        public string $postBonus,
        public string $returnedBonus,
    ) {}

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

    private static function rate(float $rate): BigDecimal
    {
        return BigDecimal::of(number_format(max(0.0, min(1.0, $rate)), 8, '.', ''));
    }
}
