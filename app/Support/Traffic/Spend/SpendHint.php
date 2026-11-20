<?php

namespace App\Support\Traffic\Spend;

use App\Enums\TrafficSpendBasis;
use App\Enums\TrafficSpendMode;
use App\Support\Traffic\Money\TrafficMoney;
use Brick\Math\RoundingMode;

/**
 * Where a click's spend comes from, as the hover on it in Logs → Clicks says
 * it, with the amounts exactly as kept: the price the link carried, and the
 * figure counted when it is not that price — the rate you set, the
 * campaign's price, or a day's figure shared out over its visits: the fixed
 * price, the network's bill, an imported file or a typed-in total. While
 * the figure is an estimate it says what it waits for: the network's bill
 * where the source takes its spend through the API, else a typed-in or
 * imported figure, and for a fixed price the end of its day. A click on a
 * fixed price is written down as nothing until the price is first shared
 * out — every quarter of an hour for the day still running, and never while
 * the source lacks its amount, period or start — so until then it says so
 * rather than claim a share.
 */
final class SpendHint
{
    public static function of(TrafficSpendBasis $basis, bool $estimated, mixed $cost, mixed $spend, ?TrafficSpendMode $sourceMode): string
    {
        $awaited = match (true) {
            $basis === TrafficSpendBasis::Fixed => 'the day is over',
            $sourceMode === TrafficSpendMode::Api => 'the network’s bill is shared out',
            default => 'a typed-in or imported figure is shared out',
        };
        $unshared = $basis === TrafficSpendBasis::Fixed && $estimated && TrafficMoney::of($spend)->toScale(TrafficMoney::SCALE, RoundingMode::HalfUp)->isZero();
        $price = $cost !== null || $basis === TrafficSpendBasis::ClickCost ? 'Price in the click '.TrafficMoney::exact($cost ?? $spend) : null;
        $counted = match (true) {
            $basis === TrafficSpendBasis::ClickCost => null,
            $basis === TrafficSpendBasis::None => $estimated ? 'not counted until '.$awaited : 'not counted',
            $unshared => 'not counted until the placement’s fixed price is shared out',
            default => 'counted '.TrafficMoney::exact($spend).', '.self::origin($basis),
        };
        $estimate = $estimated && $basis !== TrafficSpendBasis::None && ! $unshared ? 'estimate until '.$awaited : null;

        return ucfirst(implode(' · ', array_filter([$price, $counted, $estimate])));
    }

    private static function origin(TrafficSpendBasis $basis): string
    {
        return match ($basis) {
            TrafficSpendBasis::Rate => 'the rate you set',
            TrafficSpendBasis::CampaignPrice => 'the campaign’s price',
            TrafficSpendBasis::Fixed => 'the placement’s fixed price shared out',
            TrafficSpendBasis::Api => 'the network’s bill shared out',
            TrafficSpendBasis::Csv => 'an imported CSV figure shared out',
            TrafficSpendBasis::Manual => 'a typed-in figure shared out',
            TrafficSpendBasis::ClickCost, TrafficSpendBasis::None => '',
        };
    }
}
