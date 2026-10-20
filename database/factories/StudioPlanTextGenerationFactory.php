<?php

namespace Database\Factories;

use App\Enums\StudioPostTextGenerationError;
use App\Enums\StudioPostTextGenerationStatus;
use App\Enums\StudioPostTextTone;
use App\Models\StudioPlan;
use App\Models\StudioPlanTextGeneration;
use App\Support\StudioAi\StudioImageDescription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StudioPlanTextGeneration>
 */
class StudioPlanTextGenerationFactory extends Factory
{
    protected $model = StudioPlanTextGeneration::class;

    /** A queued batch for the photo of a plan made with one, written from the factory's analysis. */
    public function definition(): array
    {
        return [
            'studio_plan_id' => StudioPlan::factory()->withImage(),
            'source_image_asset_id' => fn (array $attributes): int => (int) StudioPlan::query()->whereKey($attributes['studio_plan_id'])->value('image_asset_id'),
            'source_analysis_hash' => (string) StudioImageDescription::from(StudioPlanImageAnalysisFactory::description())?->hash(),
            'tone' => StudioPostTextTone::Cheeky,
            'status' => StudioPostTextGenerationStatus::Queued,
            'attempt_id' => (string) Str::uuid(),
            'requested_at' => now(),
        ];
    }

    /** For this plan's photo as it is now, written from this analysis of it. */
    public function forPlan(StudioPlan $plan, ?array $analysis = null): static
    {
        return $this->state(fn (): array => [
            'studio_plan_id' => $plan->id,
            'source_image_asset_id' => $plan->image_asset_id,
            'source_analysis_hash' => (string) StudioImageDescription::from($analysis ?? StudioPlanImageAnalysisFactory::description())?->hash(),
        ]);
    }

    public function running(): static
    {
        return $this->state(fn (): array => [
            'status' => StudioPostTextGenerationStatus::Running,
            'started_at' => now(),
        ]);
    }

    /** Completed, with a valid batch of texts and the call that wrote it. */
    public function completed(?array $titles = null, ?array $descriptions = null): static
    {
        return $this->state(fn (): array => [
            'status' => StudioPostTextGenerationStatus::Completed,
            'started_at' => now(),
            'finished_at' => now(),
            'titles' => $titles ?? self::titles(),
            'descriptions' => $descriptions ?? self::descriptions(),
            'model' => 'gpt-4.1-mini-2025-04-14',
            'request_id' => 'resp_'.Str::random(12),
            'input_tokens' => 700,
            'output_tokens' => 900,
            'total_tokens' => 1600,
            'latency_ms' => 5200,
        ]);
    }

    public function failed(StudioPostTextGenerationError $error = StudioPostTextGenerationError::ProviderUnavailable): static
    {
        return $this->state(fn (): array => [
            'status' => StudioPostTextGenerationStatus::Failed,
            'started_at' => now(),
            'error_code' => $error,
        ]);
    }

    /**
     * Ten titles as a batch keeps them, each telling the one before from the next.
     *
     * @return list<string>
     */
    public static function titles(string $prefix = ''): array
    {
        return array_map(fn (string $title): string => $prefix.$title, [
            'Beach zoomies, level expert',
            'Sand, sea and one very fast dog',
            'Nobody told him it was cloudy',
            'The tide never stood a chance',
            'Paws first, questions later',
            'A red collar on a mission',
            'Overcast skies, full-speed joy',
            'Footprints that lead to the sea',
            'He saw the water and chose violence',
            'Running late for his swim',
        ]);
    }

    /**
     * Ten descriptions as a batch keeps them.
     *
     * @return list<string>
     */
    public static function descriptions(string $prefix = ''): array
    {
        return array_map(fn (string $description): string => $prefix.$description, [
            'A brown dog sprints across the sand towards the waves.',
            'Grey sky, open beach, and one dog who does not care about either.',
            'The red collar is the only thing keeping up with him.',
            'Every footprint here belongs to one very determined dog.',
            'Somewhere behind him, someone is still holding the lead.',
            'Low tide, high energy, zero hesitation.',
            'The sea is right there and he is not slowing down.',
            'Wide beach, soft light, and a dog in full flight.',
            'He has one plan for the afternoon and it involves getting wet.',
            'Sand flying, ears flapping, destination: the waves.',
        ]);
    }
}
