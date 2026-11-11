<?php

namespace App\Support\Games\Flow;

use App\Enums\GameFlowStepType;

/**
 * Which steps of a flow a visitor sees, and in what order. A step is skipped
 * when its audience is not theirs; an Email Capture when their email is known
 * and the step skips known emails; a Next Game step when its game is not live,
 * or gives the visitor's audience no version.
 * A skipped step is passed over, never shown empty.
 */
final class GameFlowRunner
{
    /**
     * @param  list<FlowStepDraft>  $steps
     * @return list<FlowStepDraft>
     */
    public static function visible(array $steps, FlowVisitor $visitor): array
    {
        return array_values(array_filter($steps, fn (FlowStepDraft $step): bool => self::isVisible($step, $visitor)));
    }

    public static function isVisible(FlowStepDraft $step, FlowVisitor $visitor): bool
    {
        if (! $step->audience->includes($visitor->audience)) {
            return false;
        }

        return match ($step->type) {
            GameFlowStepType::EmailCapture => ! ($visitor->emailKnown && (bool) $step->setting('skip_known')),
            GameFlowStepType::NextGame => $visitor->nextGameAvailable,
            default => true,
        };
    }

    /** @param  list<FlowStepDraft>  $steps */
    public static function first(array $steps, FlowVisitor $visitor): ?FlowStepDraft
    {
        return self::visible($steps, $visitor)[0] ?? null;
    }

    /**
     * The visible step after the one with this key. The step itself may have
     * become invisible meanwhile — a guest who registered on it — and its
     * place in the flow still counts.
     *
     * @param  list<FlowStepDraft>  $steps
     */
    public static function after(array $steps, FlowVisitor $visitor, string $key): ?FlowStepDraft
    {
        $position = self::positionOf($steps, $key);

        if ($position === null) {
            return null;
        }

        foreach (array_slice($steps, $position + 1) as $step) {
            if (self::isVisible($step, $visitor)) {
                return $step;
            }
        }

        return null;
    }

    /** @param  list<FlowStepDraft>  $steps */
    public static function find(array $steps, string $key): ?FlowStepDraft
    {
        $position = self::positionOf($steps, $key);

        return $position === null ? null : $steps[$position];
    }

    /** @param  list<FlowStepDraft>  $steps */
    public static function ofType(array $steps, GameFlowStepType $type): ?FlowStepDraft
    {
        foreach ($steps as $step) {
            if ($step->type === $type) {
                return $step;
            }
        }

        return null;
    }

    /**
     * Whether a step comes before another in the flow, by key.
     *
     * @param  list<FlowStepDraft>  $steps
     */
    public static function precedes(array $steps, string $key, string $otherKey): bool
    {
        $position = self::positionOf($steps, $key);
        $other = self::positionOf($steps, $otherKey);

        return $position !== null && $other !== null && $position < $other;
    }

    /** @param  list<FlowStepDraft>  $steps */
    private static function positionOf(array $steps, string $key): ?int
    {
        foreach ($steps as $position => $step) {
            if ($step->key === $key) {
                return $position;
            }
        }

        return null;
    }
}
