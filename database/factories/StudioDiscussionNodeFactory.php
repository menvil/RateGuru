<?php

namespace Database\Factories;

use App\Models\StudioDiscussionNode;
use App\Models\StudioPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudioDiscussionNode>
 */
class StudioDiscussionNodeFactory extends Factory
{
    /**
     * A comment on a Draft's post, a minute after it, by a Persona.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'studio_plan_id' => StudioPlan::factory(),
            'parent_id' => null,
            'persona_user_id' => User::factory()->persona(),
            'body' => fake()->sentence(),
            'sort_order' => 0,
            'offset_seconds' => 60,
        ];
    }

    /** A reply to this comment, on the same plan. */
    public function replyTo(StudioDiscussionNode $parent): static
    {
        return $this->state(fn (): array => [
            'studio_plan_id' => $parent->studio_plan_id,
            'parent_id' => $parent->id,
            'offset_seconds' => $parent->offset_seconds + 60,
        ]);
    }
}
