<?php

namespace Database\Factories;

use App\Enums\MediaKind;
use App\Enums\MediaStatus;
use App\Enums\MediaVisibility;
use App\Models\MediaAsset;
use App\Support\Media\ImageOrientationClassifier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaAsset>
 */
class MediaAssetFactory extends Factory
{
    protected $model = MediaAsset::class;

    public function definition(): array
    {
        $width = 1600;
        $height = 900;

        return [
            'owner_user_id' => null,
            'kind' => MediaKind::PostImage,
            'disk' => 'public',
            'path' => 'posts/'.fake()->uuid().'.jpg',
            'original_filename' => fake()->word().'.jpg',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'byte_size' => fake()->numberBetween(20_000, 2_000_000),
            'width' => $width,
            'height' => $height,
            'aspect_ratio' => round($width / $height, 6),
            'orientation' => app(ImageOrientationClassifier::class)->classify($width, $height),
            'checksum_sha256' => null,
            'status' => MediaStatus::Ready,
            'visibility' => MediaVisibility::Public,
            'processing_error' => null,
            'metadata' => null,
        ];
    }

    public function postImage(): static
    {
        return $this->state(fn (): array => [
            'kind' => MediaKind::PostImage,
        ]);
    }

    public function avatar(): static
    {
        return $this->state(fn (): array => [
            'kind' => MediaKind::Avatar,
            'path' => 'avatars/'.fake()->uuid().'.jpg',
        ]);
    }

    public function dimensions(int $width, int $height): static
    {
        return $this->state(fn (): array => [
            'width' => $width,
            'height' => $height,
            'aspect_ratio' => round($width / $height, 6),
            'orientation' => app(ImageOrientationClassifier::class)->classify($width, $height),
        ]);
    }

    /**
     * Dimensions that were never recorded, with everything derived from them
     * absent too.
     *
     * A row with width/height null but aspect_ratio and orientation still filled
     * in is a state the application cannot produce — those two are computed FROM
     * the dimensions — so a fixture shaped that way tests something that does not
     * exist. This is the shape of an asset stored before dimensions were probed;
     * failed() below is the same absence arriving by a different route.
     */
    public function dimensionless(): static
    {
        return $this->state(fn (): array => [
            'width' => null,
            'height' => null,
            'aspect_ratio' => null,
            'orientation' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => MediaStatus::Failed,
            'processing_error' => 'Simulated processing failure.',
            'width' => null,
            'height' => null,
            'aspect_ratio' => null,
            'orientation' => null,
        ]);
    }
}
