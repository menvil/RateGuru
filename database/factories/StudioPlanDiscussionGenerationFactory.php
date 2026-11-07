<?php

namespace Database\Factories;

use App\Enums\StudioDiscussionGenerationError;
use App\Enums\StudioDiscussionGenerationMode;
use App\Enums\StudioDiscussionGenerationStatus;
use App\Enums\StudioPostTextTone;
use App\Models\StudioPlan;
use App\Models\StudioPlanDiscussionGeneration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StudioPlanDiscussionGeneration>
 */
class StudioPlanDiscussionGenerationFactory extends Factory
{
    protected $model = StudioPlanDiscussionGeneration::class;

    /**
     * A queued whole discussion for a plan made with a photo, asked for by an
     * administrator. Its hashes are placeholders: a generation that must fit
     * a plan's context is asked for through RequestStudioDiscussionGenerationAction.
     */
    public function definition(): array
    {
        return [
            'studio_plan_id' => StudioPlan::factory()->withImage(),
            'requested_by_user_id' => User::factory()->admin(),
            'mode' => StudioDiscussionGenerationMode::FullDiscussion,
            'target_node_id' => null,
            'persona_user_ids' => [],
            'parameters' => [
                'mode' => StudioDiscussionGenerationMode::FullDiscussion->value,
                'target_node_id' => null,
                'persona_user_ids' => [],
                'root_count' => 3,
                'reply_count' => 2,
                'tone' => StudioPostTextTone::DEFAULT->value,
                'expected_lock_version' => 1,
            ],
            'source_lock_version' => 1,
            'source_image_asset_id' => fn (array $attributes): int => (int) StudioPlan::query()->whereKey($attributes['studio_plan_id'])->value('image_asset_id'),
            'source_analysis_hash' => str_repeat('a', 64),
            'source_discussion_hash' => str_repeat('b', 64),
            'source_personas_hash' => str_repeat('c', 64),
            'status' => StudioDiscussionGenerationStatus::Queued,
            'attempt_id' => (string) Str::uuid(),
            'requested_at' => now(),
        ];
    }

    public function running(): static
    {
        return $this->state(fn (): array => [
            'status' => StudioDiscussionGenerationStatus::Running,
            'started_at' => now(),
        ]);
    }

    /**
     * Completed, with this proposal as it is kept, and the call that wrote it.
     *
     * @param  array<string, mixed>  $result
     */
    public function completed(array $result = ['nodes' => []]): static
    {
        return $this->state(fn (): array => [
            'status' => StudioDiscussionGenerationStatus::Completed,
            'started_at' => now(),
            'finished_at' => now(),
            'result' => $result,
            'model' => 'gpt-4.1-mini-2025-04-14',
            'request_id' => 'resp_'.Str::random(12),
            'input_tokens' => 2400,
            'output_tokens' => 1800,
            'total_tokens' => 4200,
            'latency_ms' => 9100,
        ]);
    }

    public function failed(StudioDiscussionGenerationError $error = StudioDiscussionGenerationError::ProviderUnavailable): static
    {
        return $this->state(fn (): array => [
            'status' => StudioDiscussionGenerationStatus::Failed,
            'started_at' => now(),
            'finished_at' => now(),
            'error_code' => $error,
        ]);
    }
}
