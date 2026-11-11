<?php

namespace Database\Factories;

use App\Enums\GameFlowStepType;
use App\Enums\GameStepAudience;
use App\Models\GameFlow;
use App\Models\GameFlowStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameFlowStep>
 */
class GameFlowStepFactory extends Factory
{
    public function definition(): array
    {
        return [
            'flow_id' => GameFlow::factory(),
            'position' => 0,
            ...$this->attributesFor(GameFlowStepType::Game),
        ];
    }

    /**
     * A fresh step of a type: its fixed audience, default settings and texts.
     * The flow and position it was given are kept.
     */
    public function type(GameFlowStepType $type): static
    {
        return $this->state(fn (): array => $this->attributesFor($type));
    }

    /** @return array<string, mixed> */
    private function attributesFor(GameFlowStepType $type): array
    {
        return [
            'type' => $type,
            'audience' => $type->fixedAudience() ?? GameStepAudience::All,
            'settings' => $type->defaultSettings(),
            ...$type->defaultTexts(),
        ];
    }
}
