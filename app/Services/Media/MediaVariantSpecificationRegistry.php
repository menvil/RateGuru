<?php

namespace App\Services\Media;

use App\Enums\MediaKind;
use App\Enums\MediaResizeMode;
use App\Enums\MediaVariantName;

/**
 * The single source of truth for which variants exist per MediaKind and
 * what each one looks like. Dimensions/quality are fixed architectural
 * values, not environment-tunable config.
 */
final class MediaVariantSpecificationRegistry
{
    private const int QUALITY = 82;

    /**
     * @return list<MediaVariantSpecification>
     */
    public function for(MediaKind $kind): array
    {
        return match ($kind) {
            MediaKind::PostImage => [
                new MediaVariantSpecification(MediaVariantName::PostFeed640, 640, 1280, MediaResizeMode::Contain, self::QUALITY),
                new MediaVariantSpecification(MediaVariantName::PostFeed1280, 1280, 1920, MediaResizeMode::Contain, self::QUALITY),
                new MediaVariantSpecification(MediaVariantName::PostDetail1920, 1920, 2400, MediaResizeMode::Contain, self::QUALITY),
            ],
            MediaKind::Avatar => [
                new MediaVariantSpecification(MediaVariantName::Avatar128, 128, 128, MediaResizeMode::CoverSquare, self::QUALITY),
                new MediaVariantSpecification(MediaVariantName::Avatar256, 256, 256, MediaResizeMode::CoverSquare, self::QUALITY),
            ],
        };
    }
}
