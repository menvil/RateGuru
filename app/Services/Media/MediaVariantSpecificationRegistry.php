<?php

namespace App\Services\Media;

use App\Enums\MediaKind;
use App\Enums\MediaResizeMode;
use App\Enums\MediaVariantName;

/**
 * The single source of truth for which variants exist per MediaKind and
 * what each one looks like. Dimensions/quality are fixed architectural
 * values, not environment-tunable config.
 *
 * The variants a page shows are WebP, whatever the upload was: a 1280-wide
 * photo uploaded as PNG made a 1 MB feed variant, where WebP at the same
 * quality is under a tenth of that, and keeps transparency. The Open Graph
 * crop stays JPEG, the one format every link-preview crawler reads.
 */
final class MediaVariantSpecificationRegistry
{
    private const int QUALITY = 82;

    private const string DISPLAY_MIME_TYPE = 'image/webp';

    /**
     * @return list<MediaVariantSpecification>
     */
    public function for(MediaKind $kind): array
    {
        return match ($kind) {
            MediaKind::PostImage => [
                new MediaVariantSpecification(MediaVariantName::PostFeed640, 640, 1280, MediaResizeMode::Contain, self::QUALITY, outputMimeType: self::DISPLAY_MIME_TYPE),
                new MediaVariantSpecification(MediaVariantName::PostFeed1280, 1280, 1920, MediaResizeMode::Contain, self::QUALITY, outputMimeType: self::DISPLAY_MIME_TYPE),
                new MediaVariantSpecification(MediaVariantName::PostDetail1920, 1920, 2400, MediaResizeMode::Contain, self::QUALITY, outputMimeType: self::DISPLAY_MIME_TYPE),
                new MediaVariantSpecification(MediaVariantName::OpenGraph, 1200, 630, MediaResizeMode::Cover, self::QUALITY, outputMimeType: 'image/jpeg'),
            ],
            MediaKind::Avatar => [
                new MediaVariantSpecification(MediaVariantName::Avatar128, 128, 128, MediaResizeMode::CoverSquare, self::QUALITY, outputMimeType: self::DISPLAY_MIME_TYPE),
                new MediaVariantSpecification(MediaVariantName::Avatar256, 256, 256, MediaResizeMode::CoverSquare, self::QUALITY, outputMimeType: self::DISPLAY_MIME_TYPE),
            ],
        };
    }
}
