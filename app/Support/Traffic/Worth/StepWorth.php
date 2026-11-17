<?php

namespace App\Support\Traffic\Worth;

use App\Enums\TrafficStepValueMode;
use App\Enums\TrafficValueBasis;
use App\Support\Traffic\Money\TrafficMoney;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * What reaching a step is worth for a visit: nothing for a known visitor or a
 * sign-in; the account's worth for the account; the administrator's own
 * value when it is set to always count, or while the slice is thin; else the
 * chance of an account from the step times what an account is worth.
 */
final class StepWorth
{
    /**
     * @param  array{value: string, mode: TrafficStepValueMode}|null  $manual
     * @return array{value: BigDecimal, basis: TrafficValueBasis, chance: float}
     */
    public static function of(Slice $slice, string $step, WorthPrices $prices, int $percent, bool $known, ?array $manual, ChanceTable $table): array
    {
        $account = $prices->account(
            $table->of($slice, 'post_bonus')['chance'],
            $table->of($slice, 'returned_bonus')['chance'],
            $percent,
        );

        if ($known || $step === 'login') {
            return ['value' => BigDecimal::zero(), 'basis' => TrafficValueBasis::None, 'chance' => 0.0];
        }

        if ($step === 'account') {
            return ['value' => $account, 'basis' => TrafficValueBasis::Outcome, 'chance' => 1.0];
        }

        $chance = $table->of($slice, $step);

        if ($manual !== null && ($manual['mode'] === TrafficStepValueMode::Always || $chance['thin'])) {
            return ['value' => TrafficMoney::of($manual['value']), 'basis' => TrafficValueBasis::Manual, 'chance' => $chance['chance']];
        }

        return [
            'value' => $account->multipliedBy(BigDecimal::of(number_format($chance['chance'], 8, '.', '')))->toScale(TrafficMoney::SCALE, RoundingMode::HalfUp),
            'basis' => $chance['thin'] ? TrafficValueBasis::Leaning : TrafficValueBasis::Measured,
            'chance' => $chance['chance'],
        ];
    }
}
