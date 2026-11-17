<?php

namespace App\Support\Traffic\Reports;

/**
 * Whether one version of a game brings more accounts per new paid visitor
 * than another: a two-proportion z-test, with the confidence that the
 * difference is real. Too early with fewer than 10 accounts on either; a
 * leader from 95 % confidence; ahead, not sure yet, from 80 %, with how many
 * visitors each would need to be sure; else no clear difference.
 */
final class TwoProportionTest
{
    public const int MIN_ACCOUNTS = 10;

    /** @return array{verdict: string, confidence: float|null, needed: int|null} verdict: too_early, leader, ahead or no_difference */
    public static function compare(int $nA, int $xA, int $nB, int $xB): array
    {
        if (min($xA, $xB) < self::MIN_ACCOUNTS || $nA <= 0 || $nB <= 0) {
            return ['verdict' => 'too_early', 'confidence' => null, 'needed' => null];
        }

        $rA = $xA / $nA;
        $rB = $xB / $nB;
        $pooled = ($xA + $xB) / ($nA + $nB);
        $error = sqrt($pooled * (1 - $pooled) * (1 / $nA + 1 / $nB));
        $z = $error > 0 ? ($rA - $rB) / $error : 0.0;
        $confidence = self::erf(abs($z) / M_SQRT2);
        $needed = $rA === $rB ? null : (int) ceil(7.84 * ($rA * (1 - $rA) + $rB * (1 - $rB)) / (($rA - $rB) ** 2));

        return [
            'verdict' => match (true) {
                $confidence >= 0.95 => 'leader',
                $confidence >= 0.80 => 'ahead',
                default => 'no_difference',
            },
            'confidence' => $confidence,
            'needed' => $needed,
        ];
    }

    /** The error function, by Abramowitz and Stegun 7.1.26 (error below 1.5e-7). */
    public static function erf(float $x): float
    {
        $sign = $x < 0 ? -1 : 1;
        $x = abs($x);
        $t = 1 / (1 + 0.3275911 * $x);
        $y = 1 - (((((1.061405429 * $t - 1.453152027) * $t) + 1.421413741) * $t - 0.284496736) * $t + 0.254829592) * $t * exp(-$x * $x);

        return $sign * $y;
    }
}
