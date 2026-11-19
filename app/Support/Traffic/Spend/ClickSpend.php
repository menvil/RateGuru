<?php

namespace App\Support\Traffic\Spend;

use App\Enums\TrafficCostUnit;
use App\Enums\TrafficSpendBasis;
use App\Enums\TrafficSpendMode;
use App\Support\Traffic\Money\TrafficMoney;
use Brick\Math\RoundingMode;

/**
 * What a click is taken to have cost when it comes in, by how its campaign
 * — or else its source — counts spend: the price the link carries, a rate
 * per device, the campaign's price on that day, or an estimate (from the
 * link's price or the rate) while the network's figures, a file or a typed
 * total are still to come; a fixed price is shared out later, nothing is
 * nothing. A price per thousand is divided down to one click.
 */
final class ClickSpend
{
    /**
     * @param  string|null  $cost  the price the link carried, already per click
     * @param  array{price: string, model: string}|null  $campaignPrice  in force when the click came
     * @return array{spend: string, basis: TrafficSpendBasis, estimated: bool}
     */
    public static function of(
        TrafficSpendMode $mode,
        ?string $cost,
        ?string $rate,
        TrafficCostUnit $rateUnit,
        ?array $campaignPrice,
        ?string $estimate,
    ): array {
        $perClick = fn (?string $amount, TrafficCostUnit $unit): ?string => $amount === null ? null
            : TrafficMoney::stored(TrafficMoney::of($amount)->dividedBy($unit->divisor(), TrafficMoney::SCALE, RoundingMode::HalfUp));
        $rated = $perClick($rate, $rateUnit);

        return match ($mode) {
            TrafficSpendMode::ClickCost => ['spend' => TrafficMoney::stored($cost ?? 0), 'basis' => $cost === null ? TrafficSpendBasis::None : TrafficSpendBasis::ClickCost, 'estimated' => false],
            TrafficSpendMode::Rate => ['spend' => TrafficMoney::stored($rated ?? 0), 'basis' => $rated === null ? TrafficSpendBasis::None : TrafficSpendBasis::Rate, 'estimated' => false],
            TrafficSpendMode::Price => $campaignPrice === null
                ? ['spend' => TrafficMoney::stored(0), 'basis' => TrafficSpendBasis::None, 'estimated' => false]
                : ['spend' => (string) $perClick($campaignPrice['price'], TrafficCostUnit::from($campaignPrice['model'])), 'basis' => TrafficSpendBasis::CampaignPrice, 'estimated' => false],
            TrafficSpendMode::Api, TrafficSpendMode::Manual => match (true) {
                $estimate === TrafficSpendMode::ClickCost->value && $cost !== null => ['spend' => TrafficMoney::stored($cost), 'basis' => TrafficSpendBasis::ClickCost, 'estimated' => true],
                $estimate === TrafficSpendMode::Rate->value && $rated !== null => ['spend' => $rated, 'basis' => TrafficSpendBasis::Rate, 'estimated' => true],
                $estimate === null && $cost !== null => ['spend' => TrafficMoney::stored($cost), 'basis' => TrafficSpendBasis::ClickCost, 'estimated' => true],
                $estimate === null && $rated !== null => ['spend' => $rated, 'basis' => TrafficSpendBasis::Rate, 'estimated' => true],
                default => ['spend' => TrafficMoney::stored(0), 'basis' => TrafficSpendBasis::None, 'estimated' => true],
            },
            TrafficSpendMode::Fixed => ['spend' => TrafficMoney::stored(0), 'basis' => TrafficSpendBasis::Fixed, 'estimated' => true],
            TrafficSpendMode::None => ['spend' => TrafficMoney::stored(0), 'basis' => TrafficSpendBasis::None, 'estimated' => false],
        };
    }
}
