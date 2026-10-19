<?php

namespace Database\Factories;

use App\Enums\StudioImageAnalysisError;
use App\Enums\StudioImageAnalysisStatus;
use App\Models\StudioPlan;
use App\Models\StudioPlanImageAnalysis;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StudioPlanImageAnalysis>
 */
class StudioPlanImageAnalysisFactory extends Factory
{
    protected $model = StudioPlanImageAnalysis::class;

    /** A queued attempt for the photo of a plan made with one. */
    public function definition(): array
    {
        return [
            'studio_plan_id' => StudioPlan::factory()->withImage(),
            'source_image_asset_id' => fn (array $attributes): int => (int) StudioPlan::query()->whereKey($attributes['studio_plan_id'])->value('image_asset_id'),
            'status' => StudioImageAnalysisStatus::Queued,
            'attempt_id' => (string) Str::uuid(),
            'requested_at' => now(),
        ];
    }

    /** About this plan's photo as it is now. */
    public function forPlan(StudioPlan $plan): static
    {
        return $this->state(fn (): array => [
            'studio_plan_id' => $plan->id,
            'source_image_asset_id' => $plan->image_asset_id,
        ]);
    }

    public function running(): static
    {
        return $this->state(fn (): array => [
            'status' => StudioImageAnalysisStatus::Running,
            'started_at' => now(),
        ]);
    }

    /** Completed, with a valid description and the call that gave it. */
    public function completed(?array $result = null): static
    {
        return $this->state(fn (): array => [
            'status' => StudioImageAnalysisStatus::Completed,
            'started_at' => now(),
            'finished_at' => now(),
            'result' => $result ?? self::description(),
            'model' => 'gpt-4.1-mini-2025-04-14',
            'request_id' => 'resp_'.Str::random(12),
            'input_tokens' => 900,
            'output_tokens' => 140,
            'total_tokens' => 1040,
            'latency_ms' => 3200,
        ]);
    }

    public function failed(StudioImageAnalysisError $error = StudioImageAnalysisError::ProviderUnavailable): static
    {
        return $this->state(fn (): array => [
            'status' => StudioImageAnalysisStatus::Failed,
            'started_at' => now(),
            'error_code' => $error,
        ]);
    }

    /**
     * A description as the analyzer keeps one.
     *
     * @return array<string, string|list<string>>
     */
    public static function description(array $overrides = []): array
    {
        return array_replace([
            'summary' => 'A brown dog runs across a sandy beach towards the sea.',
            'subjects' => ['brown dog', 'sea', 'sandy beach'],
            'actions' => ['the dog is running'],
            'setting' => 'An open beach on an overcast day.',
            'composition' => 'Wide shot at ground level, the dog in the left third, soft light.',
            'notable_details' => ['a red collar', 'footprints in the sand'],
            'visible_text' => [],
            'uncertainties' => ['the breed of the dog'],
        ], $overrides);
    }
}
