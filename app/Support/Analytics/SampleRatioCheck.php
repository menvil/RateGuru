<?php

namespace App\Support\Analytics;

use App\Support\Traffic\Reports\TwoProportionTest;
use InvalidArgumentException;

/**
 * Whether a traffic period shared its visitors out between its versions the
 * way its shares say: a chi-square goodness-of-fit test of the visitors each
 * version was assigned against the shares it was given. A split this far off
 * by chance less than once in a thousand looks off — something besides the
 * shares decides who sees which version, and the comparison can't be
 * trusted. Untested while nobody was assigned, or while a version would
 * expect fewer than five visitors, below which the test isn't sound. Pure:
 * the counts come in, nothing is read.
 */
final readonly class SampleRatioCheck
{
    /** A split is off when a chance this small or smaller would make it. */
    public const float OFF_BELOW = 0.001;

    /** The fewest visitors every version must expect for the test to be sound. */
    public const int MIN_EXPECTED = 5;

    /**
     * @param  list<int>  $assigned  the visitors each version was assigned
     * @param  list<int>  $shares  the share each version was given, in the same order
     */
    public function __construct(private array $assigned, private array $shares)
    {
        if (count($assigned) !== count($shares)) {
            throw new InvalidArgumentException('Every version needs both its visitors and its share.');
        }
    }

    /** The visitors assigned to any version. */
    public function total(): int
    {
        return array_sum($this->assigned);
    }

    /** The chi-square statistic of the split; null while it can't be tested. */
    public function statistic(): ?float
    {
        $shares = array_sum($this->shares);
        $total = $this->total();

        if (count($this->assigned) < 2 || $total === 0 || $shares <= 0) {
            return null;
        }

        $statistic = 0.0;

        foreach ($this->assigned as $index => $observed) {
            $expected = $total * $this->shares[$index] / $shares;

            if ($expected < self::MIN_EXPECTED) {
                return null;
            }

            $statistic += ($observed - $expected) ** 2 / $expected;
        }

        return $statistic;
    }

    /** The chance of a split at least this far from the shares with the shares kept; null while it can't be tested. */
    public function pValue(): ?float
    {
        $statistic = $this->statistic();

        return $statistic === null ? null : self::survival($statistic, count($this->assigned) - 1);
    }

    /** Whether the split is too far from the shares to be chance. */
    public function looksOff(): bool
    {
        $p = $this->pValue();

        return $p !== null && $p < self::OFF_BELOW;
    }

    /** The split in words, as the Assigned row's evidence badge puts it. */
    public function label(): string
    {
        return match (true) {
            $this->total() === 0 => 'No visitors yet',
            $this->pValue() === null => 'Too early',
            $this->looksOff() => 'Split looks off',
            default => 'Balanced',
        };
    }

    /** The tone of its badge. */
    public function tone(): string
    {
        return $this->looksOff() ? 'warning' : 'neutral';
    }

    /**
     * The chance that a chi-square variable with these degrees of freedom is
     * at least this large: the regularised upper incomplete gamma function,
     * which for a whole number of degrees has a closed form — a finite sum
     * for an even number, the complementary error function and a finite sum
     * for an odd one.
     */
    private static function survival(float $statistic, int $degrees): float
    {
        $half = $statistic / 2;

        if ($degrees % 2 === 0) {
            $term = 1.0;
            $sum = 1.0;

            for ($i = 1; $i < intdiv($degrees, 2); $i++) {
                $term *= $half / $i;
                $sum += $term;
            }

            return min(1.0, exp(-$half) * $sum);
        }

        $tail = 1 - TwoProportionTest::erf(sqrt($half));
        $term = sqrt($statistic);
        $sum = 0.0;

        for ($i = 1; $i <= intdiv($degrees - 1, 2); $i++) {
            $sum += $term;
            $term *= $statistic / (2 * $i + 1);
        }

        return min(1.0, max(0.0, $tail + sqrt(2 / M_PI) * exp(-$half) * $sum));
    }
}
