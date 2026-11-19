<?php

namespace App\Services\Traffic;

use App\Enums\TrafficEvent;
use App\Enums\TrafficKnownReason;
use App\Enums\TrafficValueBasis;
use App\Models\GameVersion;
use App\Models\TrafficChanceRun;
use App\Models\TrafficVisit;
use App\Queries\Traffic\TrafficWorthQuery;
use App\Support\Traffic\Worth\ChanceTable;
use App\Support\Traffic\Worth\Slice;
use App\Support\Traffic\Worth\StepWorth;
use App\Support\Traffic\Worth\VisitValue;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * A visit's worth, worked out for it: what a step it reached is worth now
 * (the value a postback sends), and what the visit is worth so far and may
 * still bring — always at the prices of the visit's own set, its source's
 * own account price when that set gives it one. Reads the last run once per
 * instance, so a nightly pass over thousands of visits asks for it once.
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
            $this->worth->prices((int) $visit->price_set_id)->forSource($visit->source_id),
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
        $prices = $this->worth->prices((int) $visit->price_set_id)->forSource($visit->source_id);
        $manual = $this->worth->manualValues($visit->game_id);
        $reached = [];

        foreach ($this->reachedSteps($visit) as $step) {
            $reached[] = StepWorth::of($slice, $step, $prices, (int) $visit->worth_percent, false, $manual[$step] ?? null, $table);
        }

        return VisitValue::of(
            (bool) $visit->known,
            self::signedIn($visit),
            $visit->arrived_at,
            $visit->holds_until,
            $visit->account_at,
            $visit->signup_at,
            $visit->post_published_at !== null,
            $visit->returned_at !== null,
            $reached,
            $prices,
            $visit->source_id,
            (int) $visit->worth_percent,
            $table->of($slice, 'post_bonus')['chance'],
            $table->of($slice, 'returned_bonus')['chance'],
            $this->worth->curve($this->run()),
            $now,
        );
    }

    /** The best a step the visit reached is worth — the account's worth, once it has one; a sign-in's, once it signed in: what {value_total} says. */
    public function best(TrafficVisit $visit): BigDecimal
    {
        return $this->settled($visit)['value'];
    }

    /**
     * The step the visit reached that is worth the most, and its worth: the
     * account, once it has one; the sign-in, once the visitor signed in to an
     * account they already had — a known visitor's, never told to a network,
     * but worth what its `value_real` says. What a settled visit tells its
     * network.
     *
     * @return array{value: BigDecimal, basis: TrafficValueBasis, step: string|null}
     */
    public function settled(TrafficVisit $visit): array
    {
        // A visit that signed in after it arrived stays a sign-in, even if an account lands on it later:
        // VisitValue values it so, and what it tells its network must agree.
        $outcome = match (true) {
            self::signedIn($visit) => 'login',
            $visit->account_at !== null => 'account',
            default => null,
        };

        if ($outcome !== null) {
            $worth = $this->step($visit, $outcome);

            return ['value' => $worth['value'], 'basis' => $worth['basis'], 'step' => $outcome];
        }

        $best = ['value' => BigDecimal::zero(), 'basis' => TrafficValueBasis::None, 'step' => null];
        // Read once for every step compared, and only for this settling: a later one sees a value set by hand since.
        $slice = $this->sliceOf($visit);
        $table = $this->table($slice);
        $prices = $this->worth->prices((int) $visit->price_set_id)->forSource($visit->source_id);
        $manual = $this->worth->manualValues($visit->game_id);

        foreach ($this->reachedSteps($visit) as $step) {
            $worth = StepWorth::of($slice, $step, $prices, (int) $visit->worth_percent, (bool) $visit->known, $manual[$step] ?? null, $table);

            if ($worth['value']->isGreaterThan($best['value'])) {
                $best = ['value' => $worth['value'], 'basis' => $worth['basis'], 'step' => $step];
            }
        }

        return $best;
    }

    /** Whether the visitor turned out to have an account and signed in to it after arriving, rather than being known as they came. */
    private static function signedIn(TrafficVisit $visit): bool
    {
        return $visit->known_reason === TrafficKnownReason::Login && $visit->login_at !== null;
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

        // Every round answered on the way, not the last alone: an earlier one may be worth more, by hand or by its chance.
        // The last round of the game is its finish, reached as completed.
        for ($round = 1; $round <= min((int) $visit->rounds_answered, $rounds - 1); $round++) {
            $steps[] = 'round_'.$round;
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
