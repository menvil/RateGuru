<?php

namespace App\Support\Analytics;

use App\Enums\ComparisonEvidence;
use App\Enums\GameAudience;
use App\Enums\PeriodDataState;
use App\Enums\PeriodMetric;
use App\Support\Traffic\Reports\TwoProportionTest;

/**
 * The versions of one traffic period compared, as Growth → Experiments shows
 * them and a game's Analytics tab badges them — one rule for both.
 *
 * Every rate is a share of the visitors assigned to the version, whatever
 * they did after (intent to treat). The reference is the version with the
 * largest share, the lower number among equals; every other version's rates
 * differ from it by percentage points, and how sure that difference is comes
 * from TwoProportionTest. The primary metric is the confirmed accounts for
 * anonymous visitors and, since logged-in players make no accounts, the
 * players who reached the flow's goal: the period has enough data once every
 * version has TwoProportionTest::MIN_ACCOUNTS of it.
 *
 * A rate that can't be read says why: the version's flow never asks for an
 * email (a logged-in player is never asked), the players are signed up
 * already, nobody was sent to the version yet, or none of its accounts has had
 * its seven days to activate. Pure: the figures come in, nothing is read.
 */
final readonly class PeriodComparison
{
    public const string NOT_IN_FLOW = 'Not in this flow';

    public const string SIGNED_UP = 'Already signed up';

    public const string NEEDS_DAYS = 'Needs 7 days';

    public const string NO_VISITORS = 'No visitors yet';

    /** @var list<ComparedVersion> in version order */
    public array $versions;

    /**
     * @param  list<ComparedVersion>  $versions  the versions with a share in the period
     * @param  array<int, PeriodVersionFunnel>  $funnels  by version id; a version nobody was sent to may be missing
     */
    public function __construct(
        public GameAudience $audience,
        array $versions,
        private array $funnels = [],
    ) {
        usort($versions, fn (ComparedVersion $a, ComparedVersion $b): int => $a->number <=> $b->number);
        $this->versions = $versions;
    }

    /** The version every other one is compared with: the largest share, the lower number among equals. */
    public function reference(): ?ComparedVersion
    {
        $reference = null;

        foreach ($this->versions as $version) {
            if ($reference === null || $version->share > $reference->share) {
                $reference = $version;
            }
        }

        return $reference;
    }

    /** Whether there is anything to compare: two versions at least. */
    public function compares(): bool
    {
        return count($this->versions) >= 2;
    }

    public function funnel(ComparedVersion $version): PeriodVersionFunnel
    {
        return $this->funnels[$version->id] ?? new PeriodVersionFunnel;
    }

    /** The visitors the period assigned to any of its versions. */
    public function assigned(): int
    {
        return $this->sum(fn (PeriodVersionFunnel $funnel): int => $funnel->assigned);
    }

    /** @param  callable(PeriodVersionFunnel): int  $count */
    private function sum(callable $count): int
    {
        return array_sum(array_map(fn (ComparedVersion $version): int => $count($this->funnel($version)), $this->versions));
    }

    /** What the data badge follows: the accounts of anonymous visitors, the goal of logged-in players. */
    public function primary(): PeriodMetric
    {
        return $this->audience === GameAudience::Anonymous ? PeriodMetric::Registrations : PeriodMetric::Goal;
    }

    /** The primary metric in words, plural. */
    public function primaryNoun(): string
    {
        return $this->audience === GameAudience::Anonymous ? 'accounts' : 'players who reached the goal';
    }

    /**
     * One version, nothing to compare; nobody sent to any version yet; too
     * early while a version has fewer than TwoProportionTest::MIN_ACCOUNTS of
     * the primary metric; enough data once none has.
     */
    public function state(): PeriodDataState
    {
        if (! $this->compares()) {
            return PeriodDataState::Single;
        }

        if ($this->assigned() === 0) {
            return PeriodDataState::Empty;
        }

        return TwoProportionTest::enoughToCompare(...array_map(fn (ComparedVersion $version): int => $this->funnel($version)->count($this->primary()), $this->versions))
            ? PeriodDataState::Enough
            : PeriodDataState::Early;
    }

    /** While it is too early, which versions are short of the primary metric and by how much: “Too early: V8 has 4 of 10 accounts”. */
    public function earlyNote(): ?string
    {
        if ($this->state() !== PeriodDataState::Early) {
            return null;
        }

        $short = [];

        foreach ($this->versions as $version) {
            $count = $this->funnel($version)->count($this->primary());

            if ($count < TwoProportionTest::MIN_ACCOUNTS) {
                $short[] = $version->label().' has '.number_format($count).' of '.TwoProportionTest::MIN_ACCOUNTS.' '.$this->primaryNoun();
            }
        }

        return 'Too early: '.implode(', ', $short);
    }

    /** Why a version's rate of a metric can't be read, or null when it can. */
    public function dash(ComparedVersion $version, PeriodMetric $metric): ?string
    {
        if ($metric === PeriodMetric::Emails && ($this->audience !== GameAudience::Anonymous || ! $version->asksForEmail)) {
            return self::NOT_IN_FLOW;
        }

        if ($this->audience === GameAudience::Authenticated && in_array($metric, [PeriodMetric::Registrations, PeriodMetric::Activated], true)) {
            return self::SIGNED_UP;
        }

        $funnel = $this->funnel($version);

        if ($funnel->assigned === 0) {
            return self::NO_VISITORS;
        }

        // Decided for the period, so the column reads the same down every version: no account of it has had its 7 days yet.
        if ($metric === PeriodMetric::Activated && $this->sum(fn (PeriodVersionFunnel $each): int => $each->accounts) > 0
            && $this->sum(fn (PeriodVersionFunnel $each): int => $each->matured) === 0) {
            return self::NEEDS_DAYS;
        }

        return null;
    }

    /** A version's rate of a metric, in per cent of its visitors and unrounded; null where it can't be read. */
    public function rate(ComparedVersion $version, PeriodMetric $metric): ?float
    {
        if ($this->dash($version, $metric) !== null) {
            return null;
        }

        $funnel = $this->funnel($version);

        return $funnel->count($metric) * 100 / $funnel->assigned;
    }

    /** How far a version's rate is from the reference's, in percentage points; null for the reference, or where either rate can't be read. */
    public function delta(ComparedVersion $version, PeriodMetric $metric): ?float
    {
        $reference = $this->reference();

        if (! $this->compares() || $reference === null || $reference->id === $version->id) {
            return null;
        }

        $rate = $this->rate($version, $metric);
        $against = $this->rate($reference, $metric);

        return $rate === null || $against === null ? null : $rate - $against;
    }

    /**
     * How sure the comparison is that a version differs from the reference
     * on a metric: by design where either side's rate can't be read for a
     * reason of the flow or the audience, too early where it can't be read
     * yet, else TwoProportionTest's verdict. Null for the reference itself.
     */
    public function evidence(ComparedVersion $version, PeriodMetric $metric): ?ComparisonEvidence
    {
        $reference = $this->reference();

        if (! $this->compares() || $reference === null || $reference->id === $version->id) {
            return null;
        }

        $dash = $this->dash($version, $metric) ?? $this->dash($reference, $metric);

        if ($dash !== null) {
            return in_array($dash, [self::NOT_IN_FLOW, self::SIGNED_UP], true) ? ComparisonEvidence::ByDesign : ComparisonEvidence::TooEarly;
        }

        $mine = $this->funnel($version);
        $theirs = $this->funnel($reference);

        return match (TwoProportionTest::compare($theirs->assigned, $theirs->count($metric), $mine->assigned, $mine->count($metric))['verdict']) {
            'leader' => ComparisonEvidence::Strong,
            'ahead' => ComparisonEvidence::Ahead,
            'too_early' => ComparisonEvidence::TooEarly,
            default => ComparisonEvidence::None,
        };
    }
}
