<?php

namespace App\Support\Media;

use App\Enums\MediaKind;
use App\Enums\MediaStatus;
use App\Enums\MediaVisibility;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Images\StoredMedia;

/**
 * Builds the MediaAsset row for a just-stored file. Shared by post-image and
 * avatar uploads so both stay consistent — not a storage/URL abstraction,
 * just the one piece of asset-construction logic they both need.
 */
final class MediaAssetCreator
{
    public function __construct(
        private readonly ImageOrientationClassifier $orientationClassifier,
    ) {}

    public function create(StoredMedia $stored, MediaKind $kind, ?User $owner): MediaAsset
    {
        $hasValidDimensions = $stored->width !== null
            && $stored->height !== null
            && $stored->width > 0
            && $stored->height > 0;

        return MediaAsset::create([
            'owner_user_id' => $owner?->id,
            'kind' => $kind,
            'disk' => $stored->disk,
            'path' => $stored->path,
            'original_filename' => $stored->originalFilename,
            'mime_type' => $stored->mimeType,
            'extension' => $stored->extension,
            'byte_size' => $stored->byteSize,
            'width' => $stored->width,
            'height' => $stored->height,
            'aspect_ratio' => $hasValidDimensions
                ? round($stored->width / $stored->height, 6)
                : null,
            'orientation' => $hasValidDimensions
                ? $this->orientationClassifier->classify($stored->width, $stored->height)
                : null,
            'status' => MediaStatus::Ready,
            'visibility' => MediaVisibility::Public,
        ]);
    }
}
