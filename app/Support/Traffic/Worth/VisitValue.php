<?php

namespace App\Support\Traffic\Worth;

use App\Enums\TrafficValueBasis;
use App\Support\Traffic\Money\TrafficMoney;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * What a visit is worth so far (`value_real`): the account it brought and
 * the bonuses that account earned, times its group's share — and what it may
 * still bring (`value_expected`): before an account, the best of the steps it
 * reached, less the part of that chance its age has already used up; after
 * one, the bonuses whose windows are still open; once the visit is closed,
 * nothing more. A known visitor's visit may bring nothing more: it is worth
 * the price of a sign-in, times its group's share, when the visitor turned
 * out to have an account and signed in to it after arriving, and nothing when
 * they were known as they came. An account is priced as the visit's source
 * prices it in the visit's own set: its own account price when the set gives
 * it one, else the site's — always the site's for unpaid traffic.
 */
final class VisitValue
{
    public const int PAID_CLOSES_AFTER_WINDOW_DAYS = 7;

    public const int UNPAID_CLOSES_AFTER_DAYS = 14;

    public const int POST_BONUS_DAYS = 30;

    public const int RETURN_BONUS_DAYS = 7;

    /**
     * @param  bool  $signedIn  whether the visitor signed in, after arriving, to an account they already had
     * @param  list<array{value: BigDecimal, basis: TrafficValueBasis, chance: float}>  $reached  the worth of each step the visit reached (StepWorth)
     * @param  WorthPrices  $prices  the visit's own set
     * @param  int|null  $sourceId  the visit's paid source, null for unpaid traffic
     * @return array{real: string, expected: string}
     */
    public static function of(
        bool $known,
        bool $signedIn,
        CarbonImmutable $arrivedAt,
        ?CarbonImmutable $holdsUntil,
        ?CarbonImmutable $accountAt,
        ?CarbonImmutable $signupAt,
        bool $posted,
        bool $returned,
        array $reached,
        WorthPrices $prices,
        ?int $sourceId,
        int $percent,
        float $postRate,
        float $returnRate,
        ConfirmationCurve $curve,
        CarbonImmutable $now,
    ): array {
        $prices = $prices->forSource($sourceId);

        if ($known) {
            return ['real' => TrafficMoney::stored($signedIn ? $prices->login($percent) : 0), 'expected' => TrafficMoney::stored(0)];
        }

        if ($accountAt !== null) {
            $signedUp = $signupAt ?? $accountAt;
            $expected = BigDecimal::zero();

            if (! $posted && $now->lessThan($signedUp->addDays(self::POST_BONUS_DAYS))) {
                $expected = $expected->plus(self::share(TrafficMoney::of($prices->postBonus), $postRate, $percent));
            }

            if (! $returned && $now->lessThan($signedUp->addDays(self::RETURN_BONUS_DAYS))) {
                $expected = $expected->plus(self::share(TrafficMoney::of($prices->returnedBonus), $returnRate, $percent));
            }

            return ['real' => TrafficMoney::stored($prices->earned($posted, $returned, $percent)), 'expected' => TrafficMoney::stored($expected)];
        }

        $closesAt = self::closesAt($arrivedAt, $holdsUntil);

        if ($now->greaterThanOrEqualTo($closesAt)) {
            return ['real' => TrafficMoney::stored(0), 'expected' => TrafficMoney::stored(0)];
        }

        $spent = $curve->spent((int) $arrivedAt->diffInSeconds($now, true), (int) $arrivedAt->diffInSeconds($closesAt, true));
        $account = $prices->account($postRate, $returnRate, $percent);
        $best = BigDecimal::zero();

        foreach ($reached as $step) {
            $value = $step['basis'] === TrafficValueBasis::Manual
                ? $step['value']->multipliedBy(self::decimal(1 - $spent))
                : $account->multipliedBy(self::decimal(self::remaining($step['chance'], $spent)));

            $best = $value->isGreaterThan($best) ? $value : $best;
        }

        return ['real' => TrafficMoney::stored(0), 'expected' => TrafficMoney::stored($best)];
    }

    /** When a visit stops bringing anything new: 7 days after its paid window, 14 after an unpaid arrival. */
    public static function closesAt(CarbonImmutable $arrivedAt, ?CarbonImmutable $holdsUntil): CarbonImmutable
    {
        return $holdsUntil !== null ? $holdsUntil->addDays(self::PAID_CLOSES_AFTER_WINDOW_DAYS) : $arrivedAt->addDays(self::UNPAID_CLOSES_AFTER_DAYS);
    }

    /** The chance still to come: p(1 − F) / (1 − p·F). */
    private static function remaining(float $chance, float $spent): float
    {
        $denominator = 1 - $chance * $spent;

        return $denominator <= 0.0 ? 0.0 : $chance * (1 - $spent) / $denominator;
    }

    private static function share(BigDecimal $bonus, float $rate, int $percent): BigDecimal
    {
        return $bonus->multipliedBy(self::decimal($rate))->multipliedBy($percent)->dividedBy(100, TrafficMoney::SCALE, RoundingMode::HalfUp);
    }

    private static function decimal(float $value): BigDecimal
    {
        return BigDecimal::of(number_format(max(0.0, $value), 8, '.', ''));
    }
}
