<?php

namespace App\Enums;

/**
 * How sure a traffic period's comparison is that a version differs from the
 * reference version on one metric (PeriodComparison::evidence()): not
 * comparable by design — the metric is not in the version's flow, or the
 * audience is signed up already; too early, with too few conversions on a
 * side or no visitors yet; strong evidence from 95 % confidence; ahead, not
 * sure yet, from 80 %; or no clear difference (TwoProportionTest). Never
 * stored.
 */
enum ComparisonEvidence: string
{
    case ByDesign = 'by_design';
    case TooEarly = 'too_early';
    case Strong = 'strong';
    case Ahead = 'ahead';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::ByDesign => 'By design',
            self::TooEarly => 'Too early',
            self::Strong => 'Strong evidence',
            self::Ahead => 'Ahead, not sure yet',
            self::None => 'No clear difference',
        };
    }
}
