<?php

namespace Database\Factories;

use App\Enums\StudioActionStatus;
use App\Enums\StudioActionType;
use App\Enums\StudioPlanStatus;
use App\Models\StudioAction;
use App\Models\StudioPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudioAction>
 */
class StudioActionFactory extends Factory
{
    /**
     * An approved plan's post, waiting to run: the one action approval makes,
     * as ApproveStudioPlanAction makes it in production.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $at = now()->startOfSecond();

        return [
            'studio_plan_id' => StudioPlan::factory()->status(StudioPlanStatus::Approved),
            'parent_action_id' => null,
            'type' => StudioActionType::CreatePost,
            'actor_user_id' => User::factory()->persona(),
            'payload' => [],
            'occurs_at' => $at,
            'run_after' => $at,
            'status' => StudioActionStatus::Pending,
            'attempts' => 0,
            'deferrals' => 0,
        ];
    }

    /** Waiting for this action, as a comment waits for its post. */
    public function childOf(StudioAction $parent, StudioActionType $type = StudioActionType::CreateComment): static
    {
        return $this->state(fn (): array => [
            'studio_plan_id' => $parent->studio_plan_id,
            'parent_action_id' => $parent->id,
            'type' => $type,
        ]);
    }
}
