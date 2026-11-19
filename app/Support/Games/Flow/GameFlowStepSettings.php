<?php

namespace App\Support\Games\Flow;

use App\Enums\GameAdvanceMode;
use App\Enums\GameFlowStepType;
use App\Enums\GameOnward;
use App\Enums\GamePageDestination;
use App\Enums\GamePostTarget;
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

    /** The feed's sorts a flow can send a visitor to; empty for the site's own default. */
    public const FEED_SORTS = ['newest', 'top', 'hot'];

    /** The rating groups a post can be asked, at most. */
    public const MAX_ASK_GROUPS = 8;

    private const MAX_PATH = 200;

    private const MAX_URL = 500;

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
                'next_game_id', 'post_id' => is_numeric($value) && (int) $value > 0 ? (int) $value : null,
                'onward' => (GameOnward::tryFrom((string) $value) ?? GameOnward::Next)->value,
                'post_target' => (GamePostTarget::tryFrom((string) $value) ?? GamePostTarget::LastPick)->value,
                'destination' => (GamePageDestination::tryFrom((string) $value) ?? GamePageDestination::Category)->value,
                'feed_sort' => in_array($value, self::FEED_SORTS, true) ? $value : '',
                'ask_groups' => self::groupKeys($value),
                'category', 'tag' => self::slug($value),
                'path' => self::text($value, self::MAX_PATH),
                'url' => self::text($value, self::MAX_URL),
                default => (bool) filter_var($value, FILTER_VALIDATE_BOOL),
            };
        }

        return $settings;
    }

    /** Rating group keys, as a comma-separated list: at most eight, each a key as groups have them. */
    private static function groupKeys(mixed $value): string
    {
        $keys = [];

        foreach (explode(',', is_string($value) ? $value : '') as $key) {
            $key = strtolower(trim($key));

            if (preg_match('/^[a-z0-9]+(?:_[a-z0-9]+)*$/', $key) === 1 && ! in_array($key, $keys, true) && count($keys) < self::MAX_ASK_GROUPS) {
                $keys[] = $key;
            }
        }

        return implode(',', $keys);
    }

    private static function slug(mixed $value): string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';

        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) === 1 && strlen($value) <= 80 ? $value : '';
    }

    private static function text(mixed $value, int $max): string
    {
        return is_string($value) ? mb_substr(trim($value), 0, $max) : '';
    }

    /** A delay in half seconds between the bounds the editor offers. */
    private static function advanceSeconds(mixed $value): float
    {
        $seconds = is_numeric($value) ? (float) $value : 2.5;
        $seconds = round($seconds * 2) / 2;

        return min(self::ADVANCE_SECONDS_MAX, max(self::ADVANCE_SECONDS_MIN, $seconds));
    }
}
