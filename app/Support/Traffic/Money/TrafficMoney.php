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
    /** A price on a chip: cents from 10 cents up, a tenth of a cent below — $0.70, $0.056. */
    public static function brief(mixed $amount): string
    {
        return self::format($amount, abs((float) (string) self::of($amount)) >= 0.1 ? 2 : 3);
    }

    /**
     * A worth in a table, finer the smaller it is: $1.20, $0.172, $0.0257,
     * and under a cent three significant digits, to the eight decimals money
     * is kept to, trailing zeros dropped down to four — $0.000759, $0.0050,
     * $0.00000012 — so a click on a CPM campaign does not read as $0.0008.
     */
    public static function fine(mixed $amount): string
    {
        $value = self::of($amount)->toScale(self::SCALE, RoundingMode::HalfUp);
        $size = $value->abs();

        if ($size->isZero() || $size->isGreaterThanOrEqualTo('0.01')) {
            return self::format($value, match (true) {
                $size->isGreaterThanOrEqualTo(1) => 2,
                $size->isGreaterThanOrEqualTo('0.1') => 3,
                default => 4,
            });
        }

        // The decimal the first significant digit stands at: 4 for 0.00075900, whose unscaled 75900 has five digits.
        $first = self::SCALE - strlen((string) $size->getUnscaledValue()) + 1;

        return self::trimmed($value, min(self::SCALE, max(4, $first + 2)), 4);
    }

    /** Every decimal it is kept to, for a hover: trailing zeros dropped down to cents — $0.00079123, $1.50. */
    public static function exact(mixed $amount): string
    {
        return self::trimmed(self::of($amount)->toScale(self::SCALE, RoundingMode::HalfUp), self::SCALE, 2);
    }

    public static function format(mixed $amount, int $decimals = 2): string
    {
        $value = self::of($amount);
        $sign = $value->isNegative() ? '−' : '';

        return $sign.'$'.number_format(abs((float) (string) $value), $decimals, '.', ',');
    }

    /** Rounded to so many decimals, trailing zeros dropped down to the least kept, with a true minus. */
    private static function trimmed(BigDecimal $value, int $decimals, int $least): string
    {
        [$whole, $fraction] = explode('.', (string) $value->abs()->toScale($decimals, RoundingMode::HalfUp));

        return ($value->isNegative() ? '−' : '').'$'.number_format((int) $whole, 0, '.', ',').'.'.str_pad(rtrim($fraction, '0'), $least, '0');
    }
}
