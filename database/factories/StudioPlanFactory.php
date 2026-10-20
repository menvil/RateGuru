<?php

namespace Database\Factories;

use App\Enums\StudioPlanPublicationMode;
use App\Enums\StudioPlanStatus;
use App\Models\MediaAsset;
use App\Models\StudioPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudioPlan>
 */
class StudioPlanFactory extends Factory
{
    /**
     * An empty Draft an administrator has just made. Production plans are
     * only ever made by CreateStudioPlanAction.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status' => StudioPlanStatus::Draft,
            'author_user_id' => null,
            'created_by_user_id' => User::factory()->admin(),
            'image_asset_id' => null,
            'title' => null,
            'description' => null,
            'source_url' => null,
            'category_id' => null,
            'tag_ids' => [],
            'author_answer_option_ids' => [],
            'publication_mode' => StudioPlanPublicationMode::Now,
            'publish_at' => null,
            'lock_version' => 1,
        ];
    }

    /** Written by its Persona, with a title. */
    public function authoredBy(User $persona): static
    {
        return $this->state(fn (): array => [
            'author_user_id' => $persona->id,
            'title' => fake()->sentence(4),
        ]);
    }

    /** With a photo its creator uploaded. */
    public function withImage(): static
    {
        return $this->state(fn (): array => [
            // Resolved after the creator, so the photo is theirs.
            'image_asset_id' => fn (array $attributes) => MediaAsset::factory()->postImage()->state(['owner_user_id' => $attributes['created_by_user_id']]),
        ]);
    }

    /** Moved past the Draft, as approval, a run or a cancellation will move it. */
    public function status(StudioPlanStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
