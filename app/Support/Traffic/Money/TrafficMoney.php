<?php

namespace App\Support\Traffic\Money;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Amounts of money in US dollars, worked with as exact decimals — a click on
 * a CPM campaign can cost 0.00002 — and read back the same way whatever the
 * database engine returned: PostgreSQL and MariaDB hand a SUM back as a
 * string, SQLite as a float.
 */
final class TrafficMoney
{
    public const int SCALE = 8;

    public static function of(mixed $amount): BigDecimal
    {
        if ($amount instanceof BigDecimal) {
            return $amount;
        }

        if ($amount === null || $amount === '') {
            return BigDecimal::zero();
        }

        if (is_float($amount)) {
            $amount = number_format($amount, self::SCALE, '.', '');
        }

        return BigDecimal::of((string) $amount)->toScale(self::SCALE, RoundingMode::HalfUp);
    }

    /** As stored: eight decimals, or as many as the column keeps. */
    public static function stored(mixed $amount, int $scale = self::SCALE): string
    {
        return (string) self::of($amount)->toScale($scale, RoundingMode::HalfUp);
    }

    /** For a postback: up to six decimals, without trailing zeros. */
    public static function forPostback(mixed $amount): string
    {
        $text = (string) self::of($amount)->toScale(6, RoundingMode::HalfUp);

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    /** For people: "$1.23", "−$0.40", small amounts with as many decimals as they need. */
    public static function format(mixed $amount, int $decimals = 2): string
    {
        $value = self::of($amount);
        $sign = $value->isNegative() ? '−' : '';

        return $sign.'$'.number_format(abs((float) (string) $value), $decimals, '.', ',');
    }
}
