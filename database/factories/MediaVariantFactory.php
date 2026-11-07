<?php

namespace Database\Factories;

use App\Enums\MediaKind;
use App\Enums\MediaResizeMode;
use App\Enums\MediaVariantName;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Services\Media\MediaVariantSpecification;
use App\Services\Media\MediaVariantSpecificationRegistry;
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
     * and the size a 16:9 photo — the asset factory's shape — fits to; the box
     * itself for a cropped one.
     *
     * @return array{name: MediaVariantName, path: string, mime_type: string, extension: string, width: int, height: int}
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
            'width' => $specification->maxWidth,
            'height' => $specification->mode === MediaResizeMode::Contain
                ? intdiv($specification->maxWidth * 9, 16)
                : $specification->maxHeight,
        ];
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
