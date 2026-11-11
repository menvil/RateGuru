<?php

namespace App\Support\Games\Flow;

use App\Enums\GameFlowStepType;
use App\Enums\GameSignupMode;

/**
 * The flows a new one starts from. Each is valid as it is (GameFlowValidator)
 * and gives every step its type's default texts and settings; the landing
 * hero takes the game's title.
 */
enum GameFlowTemplate: string
{
    case ResultFirst = 'result_first';
    case EmailBeforeResult = 'email_before_result';
    case DirectPlay = 'direct_play';

    public function label(): string
    {
        return match ($this) {
            self::ResultFirst => 'Result First',
            self::EmailBeforeResult => 'Email Before Result',
            self::DirectPlay => 'Direct Play',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ResultFirst => 'Landing, the game, then the full result with sign-up on it.',
            self::EmailBeforeResult => 'Landing, the game, a result preview, an optional email, the full result, then sign-up.',
            self::DirectPlay => 'Straight into the game, then the result, sharing and the feed.',
        };
    }

    /**
     * The steps, in order, with the settings that differ from their type's
     * defaults.
     *
     * @return list<array{type: GameFlowStepType, settings: array<string, bool|int|float|string|null>}>
     */
    public function steps(): array
    {
        $step = fn (GameFlowStepType $type, array $settings = []): array => ['type' => $type, 'settings' => [...$type->defaultSettings(), ...$settings]];

        return match ($this) {
            self::ResultFirst => [
                $step(GameFlowStepType::Landing),
                $step(GameFlowStepType::Game),
                $step(GameFlowStepType::FullResult),
            ],
            self::EmailBeforeResult => [
                $step(GameFlowStepType::Landing),
                $step(GameFlowStepType::Game),
                $step(GameFlowStepType::ResultPreview),
                $step(GameFlowStepType::EmailCapture),
                $step(GameFlowStepType::FullResult, ['signup' => GameSignupMode::Step->value]),
                $step(GameFlowStepType::Registration),
            ],
            self::DirectPlay => [
                $step(GameFlowStepType::Game),
                $step(GameFlowStepType::FullResult),
                $step(GameFlowStepType::Share),
                $step(GameFlowStepType::Feed),
            ],
        };
    }
}
