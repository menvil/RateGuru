<?php

namespace Database\Factories;

use App\Models\MediaAsset;
use App\Models\MediaVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaVariant>
 */
class MediaVariantFactory extends Factory
{
    protected $model = MediaVariant::class;

    public function definition(): array
    {
        return [
            'media_asset_id' => MediaAsset::factory(),
            'name' => 'feed_640',
            'disk' => 'public',
            'path' => 'posts/variants/'.fake()->uuid().'.jpg',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'byte_size' => fake()->numberBetween(10_000, 500_000),
            'width' => 640,
            'height' => 360,
            'checksum_sha256' => null,
            'metadata' => null,
        ];
    }

    public function named(string $name): static
    {
        return $this->state(fn (): array => ['name' => $name]);
    }
}
