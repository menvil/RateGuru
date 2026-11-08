<?php

namespace Database\Factories;

use App\Enums\MediaKind;
use App\Enums\MediaResizeMode;
use App\Enums\MediaVariantName;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Services\Media\MediaVariantSpecification;
use App\Services\Media\MediaVariantSpecificationRegistry;
use Closure;
use Illuminate\Database\Eloquent\Factories\Factory;
use LogicException;

/**
 * @extends Factory<MediaVariant>
 */
class MediaVariantFactory extends Factory
{
    protected $model = MediaVariant::class;

    public function definition(): array
    {
        return [
            // Large enough that every variant is made without upscaling.
            'media_asset_id' => MediaAsset::factory()->dimensions(1920, 1080),
            ...self::madeAs(MediaVariantName::PostFeed640),
            'disk' => 'public',
            'byte_size' => fake()->numberBetween(10_000, 500_000),
            'checksum_sha256' => null,
            'metadata' => null,
        ];
    }

    public function named(MediaVariantName $name): static
    {
        return $this->state(fn (): array => self::madeAs($name));
    }

    /**
     * The variant as MediaVariantSpecificationRegistry has it made: its type,
     * and its size — fitted from its asset's, as GdImageVariantProcessor fits
     * a Contain variant; the box itself for a cropped one. A width or height
     * given to the factory still wins.
     *
     * @return array{name: MediaVariantName, path: string, mime_type: string, extension: string, width: int|Closure(array<string, mixed>): int, height: int|Closure(array<string, mixed>): int}
     */
    private static function madeAs(MediaVariantName $name): array
    {
        $specification = self::specification($name);
        $mimeType = $specification->outputMimeType ?? 'image/jpeg';
        $extension = $mimeType === 'image/webp' ? 'webp' : 'jpg';

        return [
            'name' => $name,
            'path' => 'posts/variants/'.fake()->uuid().'.'.$extension,
            'mime_type' => $mimeType,
            'extension' => $extension,
            'width' => $specification->mode === MediaResizeMode::Contain
                ? fn (array $attributes): int => self::fitted($specification, $attributes['media_asset_id'] ?? null)[0]
                : $specification->maxWidth,
            'height' => $specification->mode === MediaResizeMode::Contain
                ? fn (array $attributes): int => self::fitted($specification, $attributes['media_asset_id'] ?? null)[1]
                : $specification->maxHeight,
        ];
    }

    /**
     * Within the box, never larger than the asset: the processor's Contain.
     * An asset with no recorded size is taken as the factory's 1920x1080.
     *
     * @return array{0: int, 1: int}
     */
    private static function fitted(MediaVariantSpecification $specification, mixed $assetId): array
    {
        $asset = is_int($assetId) || is_string($assetId) ? MediaAsset::query()->withTrashed()->find($assetId) : null;
        $width = $asset?->width ?: 1920;
        $height = $asset?->height ?: 1080;
        $scale = min($specification->maxWidth / $width, $specification->maxHeight / $height, 1.0);

        return [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))];
    }

    private static function specification(MediaVariantName $name): MediaVariantSpecification
    {
        $registry = app(MediaVariantSpecificationRegistry::class);

        foreach ([MediaKind::PostImage, MediaKind::Avatar] as $kind) {
            foreach ($registry->for($kind) as $specification) {
                if ($specification->name === $name) {
                    return $specification;
                }
            }
        }

        throw new LogicException("No variant specification is registered for {$name->value}.");
    }
}
