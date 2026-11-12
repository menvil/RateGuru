<?php

namespace App\Data\Games;

use App\Enums\GameFlowStepType;
use App\Enums\GameStepAudience;
use App\Models\GameFlow;
use App\Models\GameFlowStep;
use App\Support\Games\Flow\FlowStepDraft;
use App\Support\Games\Flow\GameFlowStepSettings;
use App\Support\Games\Flow\GameFlowValidator;

/**
 * A flow's steps as the Flow tab edits them, as plain arrays, in order: each
 * with its id once saved, a key that stays the same while it is edited, its
 * type, audience, settings and English texts by column.
 */
final readonly class GameFlowDraft
{
    /** @param  list<array<string, mixed>>  $steps */
    public function __construct(public array $steps) {}

    public static function of(GameFlow $flow): self
    {
        return new self($flow->steps->map(fn (GameFlowStep $step): array => self::stepOf($step))->values()->all());
    }

    /** @return array<string, mixed> */
    public static function stepOf(GameFlowStep $step): array
    {
        $texts = [];

        foreach (GameFlowStepType::TEXT_COLUMNS as $column) {
            $texts[$column] = (string) $step->getAttribute($column);
        }

        return [
            'id' => (int) $step->id,
            'key' => $step->key(),
            'type' => $step->type->value,
            'audience' => $step->audience->value,
            'settings' => $step->settings ?? [],
            'texts' => $texts,
        ];
    }

    /**
     * A new step of a type, as an editor adds it to the flow given: the step
     * the flow rules checked where it is added (GameFlowValidator::fresh).
     *
     * @param  list<FlowStepDraft>  $flow
     * @return array<string, mixed>
     */
    public static function newStep(GameFlowStepType $type, string $key, array $flow = []): array
    {
        $fresh = GameFlowValidator::fresh($type, $key, $flow);
        $texts = array_fill_keys(GameFlowStepType::TEXT_COLUMNS, '');

        return [
            'id' => null,
            'key' => $key,
            'type' => $type->value,
            'audience' => $fresh->audience->value,
            'settings' => GameFlowStepSettings::normalize($type, $fresh->settings),
            'texts' => [...$texts, ...$fresh->texts],
        ];
    }

    /**
     * The steps as the flow rules read them; a step of an unknown type is
     * left out.
     *
     * @return list<FlowStepDraft>
     */
    public function drafts(): array
    {
        $drafts = [];

        foreach ($this->steps as $step) {
            $type = GameFlowStepType::tryFrom((string) ($step['type'] ?? ''));

            if ($type === null) {
                continue;
            }

            $drafts[] = new FlowStepDraft(
                key: (string) $step['key'],
                type: $type,
                audience: GameStepAudience::tryFrom((string) ($step['audience'] ?? '')) ?? GameStepAudience::All,
                settings: GameFlowStepSettings::normalize($type, (array) ($step['settings'] ?? [])),
                texts: array_map(fn (mixed $text): string => (string) $text, (array) ($step['texts'] ?? [])),
            );
        }

        return $drafts;
    }
}
