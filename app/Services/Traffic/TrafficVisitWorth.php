<?php

namespace App\Services\Traffic;

use App\Enums\TrafficEvent;
use App\Enums\TrafficValueBasis;
use App\Models\GameVersion;
use App\Models\TrafficChanceRun;
use App\Models\TrafficVisit;
use App\Queries\Traffic\TrafficWorthQuery;
use App\Support\Traffic\Worth\ChanceTable;
use App\Support\Traffic\Worth\Slice;
use App\Support\Traffic\Worth\StepWorth;
use App\Support\Traffic\Worth\TrafficStep;
use App\Support\Traffic\Worth\VisitValue;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * A visit's worth, worked out for it: what a step it reached is worth now
 * (the value a postback sends), and what the visit is worth so far and may
 * still bring. Reads the last run once per instance, so a nightly pass over
 * thousands of visits asks for it once.
 */
final class TrafficVisitWorth
{
    private bool $runRead = false;

    private ?TrafficChanceRun $run = null;

    /** @var array<string, ChanceTable> */
    private array $tables = [];

    /** @var array<int, int> */
    private array $rounds = [];

    public function __construct(private readonly TrafficWorthQuery $worth) {}

    public function sliceOf(TrafficVisit $visit): Slice
    {
        return new Slice(
            $visit->game_id,
            $visit->source_id,
            $visit->source_id === null ? $visit->channel->value : null,
            $visit->device->value,
            $visit->country_group,
        );
    }

    /** @return array{value: BigDecimal, basis: TrafficValueBasis, chance: float} */
    public function step(TrafficVisit $visit, string $step): array
    {
        $slice = $this->sliceOf($visit);

        return StepWorth::of(
            $slice,
            $step,
            $this->worth->prices((int) $visit->price_set_id),
            (int) $visit->worth_percent,
            (bool) $visit->known,
            $this->worth->manualValues($visit->game_id)[$step] ?? null,
            $this->table($slice),
        );
    }

    /** @return array{real: string, expected: string} */
    public function values(TrafficVisit $visit, CarbonImmutable $now): array
    {
        $slice = $this->sliceOf($visit);
        $table = $this->table($slice);
        $prices = $this->worth->prices((int) $visit->price_set_id);
        $manual = $this->worth->manualValues($visit->game_id);
        $reached = [];

        foreach ($this->reachedSteps($visit) as $step) {
            $reached[] = StepWorth::of($slice, $step, $prices, (int) $visit->worth_percent, false, $manual[$step] ?? null, $table);
        }

        return VisitValue::of(
            (bool) $visit->known,
            $visit->arrived_at,
            $visit->holds_until,
            $visit->account_at,
            $visit->signup_at,
            $visit->post_published_at !== null,
            $visit->returned_at !== null,
            $reached,
            $prices,
            (int) $visit->worth_percent,
            $table->of($slice, 'post_bonus')['chance'],
            $table->of($slice, 'returned_bonus')['chance'],
            $this->worth->curve($this->run()),
            $now,
        );
    }

    /** The best a step the visit reached is worth — the account's worth, once it has one: what {value_total} says. */
    public function best(TrafficVisit $visit): BigDecimal
    {
        return $this->settled($visit)['value'];
    }

    /**
     * The step the visit reached that is worth the most, and its worth: the
     * account, once it has one. What a settled visit tells its network.
     *
     * @return array{value: BigDecimal, basis: TrafficValueBasis, step: string|null}
     */
    public function settled(TrafficVisit $visit): array
    {
        if ($visit->account_at !== null) {
            $account = $this->step($visit, 'account');

            return ['value' => $account['value'], 'basis' => $account['basis'], 'step' => 'account'];
        }

        $best = ['value' => BigDecimal::zero(), 'basis' => TrafficValueBasis::None, 'step' => null];

        foreach ($this->reachedSteps($visit) as $step) {
            $worth = $this->step($visit, $step);

            if ($worth['value']->isGreaterThan($best['value'])) {
                $best = ['value' => $worth['value'], 'basis' => $worth['basis'], 'step' => $step];
            }
        }

        return $best;
    }

    /** @return list<string> the steps before an account the visit reached */
    private function reachedSteps(TrafficVisit $visit): array
    {
        $steps = [];

        foreach ([TrafficEvent::Opened, TrafficEvent::Started, TrafficEvent::Completed, TrafficEvent::Email, TrafficEvent::Shared, TrafficEvent::Replayed,
            TrafficEvent::NextGame, TrafficEvent::Page, TrafficEvent::Feed, TrafficEvent::PostOpened, TrafficEvent::PostVoted, TrafficEvent::PostRated,
            TrafficEvent::FeedVoted, TrafficEvent::Signup] as $event) {
            if ($visit->getAttribute($event->column()) !== null) {
                $steps[] = $event->value;
            }
        }

        $rounds = $this->roundsOf($visit->version_id);

        if ($rounds > 1 && $visit->rounds_answered > 0 && $visit->rounds_answered < $rounds) {
            $steps[] = TrafficStep::round((int) $visit->rounds_answered, $rounds);
        }

        return $steps;
    }

    private function roundsOf(?int $versionId): int
    {
        if ($versionId === null) {
            return 0;
        }

        return $this->rounds[$versionId] ??= (int) GameVersion::query()->whereKey($versionId)->value('round_count');
    }

    private function table(Slice $slice): ChanceTable
    {
        return $this->tables[$slice->key()] ??= $this->worth->table($this->run(), $slice);
    }

    private function run(): ?TrafficChanceRun
    {
        if (! $this->runRead) {
            $this->run = $this->worth->lastRun();
            $this->runRead = true;
        }

        return $this->run;
    }
}
