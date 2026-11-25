<?php

namespace Database\Factories;

use App\Enums\ClassificationMode;
use App\Enums\ClassificationStatus;
use App\Models\MediaAsset;
use App\Models\MediaClassification;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MediaClassification> */
class MediaClassificationFactory extends Factory
{
    protected $model = MediaClassification::class;

    public function definition(): array
    {
        return [
            'media_asset_id' => MediaAsset::factory(),
            'classifier' => 'test-classifier',
            'model_version' => '1',
            'mode' => ClassificationMode::Shadow,
            'status' => ClassificationStatus::Succeeded,
            'inputs' => ['variant' => 'post_feed_1280'],
            'scores' => ['breast_exposed' => 0.02, 'underwear' => 0.4],
            'frame_scores' => null,
            'raw' => null,
            'latency_ms' => 120,
            'cost_microusd' => 0,
            'error' => null,
        ];
    }
}
