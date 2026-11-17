<?php

namespace App\Support\Traffic\Spend;

use App\Enums\TrafficFixedPeriod;
use App\Support\Traffic\Money\TrafficMoney;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * A placement's fixed price as a cost per day: paid per day, per week (7
 * days) or per month (to the same date next month), from its first day; each
 * day of a block costs the block's price divided by its days, the rounding
 * left over going to the block's last day; a one-off price falls on its
 * first day. Nothing after its last day, nothing after today.
 */
final class FixedPriceSchedule
{
    /** @return array<string, string> the cost of each day, by day (Y-m-d), from the first day to today */
    public static function days(string $amount, TrafficFixedPeriod $period, string $from, ?string $to, string $today): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse(min($to ?? $today, $today))->startOfDay();
        $days = [];

        if ($end->lessThan($start)) {
            return [];
        }

        if ($period === TrafficFixedPeriod::Once) {
            return [$start->toDateString() => TrafficMoney::stored($amount)];
        }

        for ($blockStart = $start; $blockStart->lessThanOrEqualTo($end); $blockStart = $blockEnd) {
            $blockEnd = match ($period) {
                TrafficFixedPeriod::Day => $blockStart->addDay(),
                TrafficFixedPeriod::Week => $blockStart->addDays(7),
                default => $blockStart->addMonthNoOverflow(),
            };
            $length = (int) $blockStart->diffInDays($blockEnd, true);
            $share = TrafficMoney::of($amount)->dividedBy($length, TrafficMoney::SCALE, RoundingMode::Down);
            $spent = TrafficMoney::of(0);

            for ($day = $blockStart, $index = 0; $day->lessThan($blockEnd); $day = $day->addDay(), $index++) {
                $cost = $index === $length - 1 ? TrafficMoney::of($amount)->minus($spent) : $share;
                $spent = $spent->plus($share);

                if ($day->lessThanOrEqualTo($end)) {
                    $days[$day->toDateString()] = TrafficMoney::stored($cost);
                }
            }
        }

        return $days;
    }
}
