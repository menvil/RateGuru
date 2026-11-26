<?php

namespace App\Services\Media;

use App\Models\MediaAsset;

/**
 * Where a video's original is, once processing has moved the asset to its
 * master: metadata.original (VideoAssetProcessor records it first thing).
 * Null for a photo, for an upload no run has started on (its own location is
 * still the original), and once the original has been deleted.
 */
final class VideoOriginal
{
    public static function of(MediaAsset $asset): ?MediaLocation
    {
        $original = ($asset->metadata ?? [])['original'] ?? null;

        if (! is_array($original) || ! is_string($original['disk'] ?? null) || ! is_string($original['path'] ?? null)) {
            return null;
        }

        $location = new MediaLocation($original['disk'], $original['path']);

        // Not yet moved: the asset's own file is the original, and is the
        // asset's to delete as its own.
        return $location->disk === $asset->disk && $location->path === $asset->path ? null : $location;
    }
}
