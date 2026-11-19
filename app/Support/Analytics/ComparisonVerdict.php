<?php

namespace App\Support\Analytics;

use App\Enums\ComparisonEvidence;
use App\Enums\PeriodDataState;
use App\Enums\PeriodMetric;

/**
 * What a traffic period's comparison says so far, as the strip above
 * Analytics → Experiments' table puts it, on the primary metric
 * (PeriodComparison::primary()).
 *
 * A version won once the evidence on it is strong: another version ahead of
 * the reference — the one with the highest rate where several are — or the
 * reference itself when every other version is behind it. Short of that, a
 * period of a paused game says no visitor is being assigned; one still open
 * says there is no winner yet, how far the versions are and what the figures
 * still lack; one that ended says the versions didn't clearly differ, or
 * that there was too little to tell. Pure: the comparison comes in, nothing
 * is read.
 */
final readonly class ComparisonVerdict
{
    public const string WON = 'won';

    public const string PAUSED = 'paused';

    public const string RUNNING = 'running';

    public const string ENDED = 'ended';

    public const string PAUSED_TEXT = 'New visitors aren’t being assigned while the game is paused. Results so far are below.';

    private function __construct(
        public string $kind,
        public string $title,
        public string $text,
        public ?ComparedVersion $winner = null,
    ) {}

    /**
     * @param  bool  $ended  whether the period is over
     * @param  bool  $paused  whether the period is open while its game is paused
     * @param  string  $when  “Day 8” for a period still open; the day it ended, “22 Sep”, for one over
     */
    public static function of(PeriodComparison $comparison, bool $ended, bool $paused, string $when): self
    {
        $primary = $comparison->primary();
        $noun = $comparison->primaryNoun();
        $reference = $comparison->reference();
        $won = $reference === null ? null : self::won($comparison, $primary, $reference, $noun);

        if ($won !== null) {
            return $won;
        }

        if ($paused && ! $ended) {
            return new self(self::PAUSED, 'Paused.', self::PAUSED_TEXT);
        }

        $state = $comparison->state();

        if (! $ended) {
            $detail = match ($state) {
                PeriodDataState::Empty, PeriodDataState::Single => 'Numbers appear as visitors arrive.',
                PeriodDataState::Early => $comparison->earlyNote().'.',
                PeriodDataState::Enough => ucfirst($noun).' so far: '.self::against($comparison, $primary, $reference).'; no version leads with strong evidence.',
            };

            return new self(self::RUNNING, 'No winner yet.', $when.'. '.$detail.self::activation($comparison));
        }

        return match ($state) {
            // A version clearly behind with none clearly ahead names no winner, but is no lack of a difference either.
            PeriodDataState::Enough => self::anyStrong($comparison, $primary, $reference)
                ? new self(self::ENDED, 'No winner on '.$noun.'.', 'The period ended '.$when.'. '.ucfirst($noun).': '.self::against($comparison, $primary, $reference).'; no version leads with strong evidence.')
                : new self(self::ENDED, 'No clear difference on '.$noun.'.', 'The period ended '.$when.'.'),
            PeriodDataState::Early => new self(self::ENDED, 'No winner.', 'The period ended '.$when.'. '.$comparison->earlyNote().'.'),
            PeriodDataState::Empty, PeriodDataState::Single => new self(self::ENDED, 'No winner.', 'The period ended '.$when.'. Nobody was sent to a version in it.'),
        };
    }

    /** Whether a version other than the reference differs from it on the metric with strong evidence. */
    private static function anyStrong(PeriodComparison $comparison, PeriodMetric $metric, ?ComparedVersion $reference): bool
    {
        foreach ($comparison->versions as $version) {
            if ($reference !== null && $version->id !== $reference->id && $comparison->evidence($version, $metric) === ComparisonEvidence::Strong) {
                return true;
            }
        }

        return false;
    }

    /** A relative difference as the screen writes it: “+12.0%”, “−4.3%”; a dash for none. */
    public static function change(?float $relative): string
    {
        if ($relative === null) {
            return '—';
        }

        $rounded = round($relative, 1);

        return ($rounded < 0 ? '−' : '+').number_format(abs($rounded), 1).'%';
    }

    /**
     * The winner on the primary metric, if the evidence names one: the
     * version ahead of the reference with strong evidence and the highest
     * rate, or the reference when every other version is behind it so.
     */
    private static function won(PeriodComparison $comparison, PeriodMetric $primary, ComparedVersion $reference, string $noun): ?self
    {
        $ahead = [];
        $behind = [];
        $others = 0;

        foreach ($comparison->versions as $version) {
            if ($version->id === $reference->id) {
                continue;
            }

            $others++;
            $relative = $comparison->relative($version, $primary);

            if ($relative === null || $comparison->evidence($version, $primary) !== ComparisonEvidence::Strong) {
                continue;
            }

            if ($relative > 0) {
                $ahead[] = $version;
            } else {
                $behind[] = $version;
            }
        }

        if ($ahead !== []) {
            usort($ahead, fn (ComparedVersion $a, ComparedVersion $b): int => ($comparison->rate($b, $primary) <=> $comparison->rate($a, $primary)) ?: $a->number <=> $b->number);
            $winner = $ahead[0];

            return new self(
                self::WON,
                $winner->label().' won on '.$noun.'.',
                self::against($comparison, $primary, $reference, $ahead).(count($ahead) > 1 ? ', each with strong evidence.' : ', with strong evidence.'),
                $winner,
            );
        }

        if ($others > 0 && count($behind) === $others) {
            return new self(
                self::WON,
                $reference->label().' won on '.$noun.'.',
                self::against($comparison, $primary, $reference, $behind).($others > 1 ? ', each with strong evidence.' : ', with strong evidence.'),
                $reference,
            );
        }

        return null;
    }

    /**
     * How far the versions given — every version but the reference, unless
     * named — are from the reference on a metric: “V8 −17.1%, V9 +3.0%
     * against V7”.
     *
     * @param  list<ComparedVersion>|null  $versions
     */
    private static function against(PeriodComparison $comparison, PeriodMetric $metric, ?ComparedVersion $reference, ?array $versions = null): string
    {
        $parts = [];

        foreach ($versions ?? $comparison->versions as $version) {
            if ($reference !== null && $version->id !== $reference->id) {
                $parts[] = $version->label().' '.self::change($comparison->relative($version, $metric));
            }
        }

        return implode(', ', $parts).' against '.($reference?->label() ?? '—');
    }

    /** What the activation figures still wait for: none of the period's accounts has had its seven days. */
    private static function activation(PeriodComparison $comparison): string
    {
        foreach ($comparison->versions as $version) {
            if ($comparison->dash($version, PeriodMetric::Activated) === PeriodComparison::NEEDS_DAYS) {
                return ' Activation waits for accounts to have their 7 days.';
            }
        }

        return '';
    }
}
