<?php

namespace App\Support\Games\Flow;

use App\Enums\GameAdvanceMode;
use App\Enums\GameFlowStepType;
use App\Enums\GameSignupMode;

/**
 * The settings a step of each type takes, and nothing else: unknown keys are
 * dropped, known ones coerced to their type and bounds, missing ones taken
 * from the type's defaults. A stored step therefore always has a complete,
 * well-formed `settings`, whatever an editor sent.
 */
final class GameFlowStepSettings
{
    public const ADVANCE_SECONDS_MIN = 1.5;

    public const ADVANCE_SECONDS_MAX = 8.0;

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, bool|int|float|string|null>
     */
    public static function normalize(GameFlowStepType $type, array $input): array
    {
        $settings = [];

        foreach ($type->defaultSettings() as $key => $default) {
            $value = $input[$key] ?? $default;

            $settings[$key] = match ($key) {
                'advance' => (GameAdvanceMode::tryFrom((string) $value) ?? GameAdvanceMode::Auto)->value,
                'advance_seconds' => self::advanceSeconds($value),
                'signup' => (GameSignupMode::tryFrom((string) $value) ?? GameSignupMode::Inline)->value,
                'next_game_id' => is_numeric($value) && (int) $value > 0 ? (int) $value : null,
                default => (bool) filter_var($value, FILTER_VALIDATE_BOOL),
            };
        }

        return $settings;
    }

    /** A delay in half seconds between the bounds the editor offers. */
    private static function advanceSeconds(mixed $value): float
    {
        $seconds = is_numeric($value) ? (float) $value : 2.5;
        $seconds = round($seconds * 2) / 2;

        return min(self::ADVANCE_SECONDS_MAX, max(self::ADVANCE_SECONDS_MIN, $seconds));
    }
}
