<?php

namespace App\Support\Games\Drafts;

use App\Enums\MediaStatus;
use App\Enums\MediaVariantName;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Support\Games\Versus\ImageState;

/**
 * Whether a picture can be shown to players. Players get its web sizes, never
 * the original, so a picture whose feed-sized variant is not made yet is
 * still processing. Reads the `variants` relation only when it is loaded.
 */
final class DraftImageState
{
    public static function of(?MediaAsset $asset): ImageState
    {
        if ($asset === null || $asset->trashed() || $asset->status === MediaStatus::Failed) {
            return ImageState::Unavailable;
        }

        if ($asset->status !== MediaStatus::Ready || ! $asset->relationLoaded('variants')) {
            return ImageState::Processing;
        }

        $hasFeedSize = $asset->variants->contains(fn (MediaVariant $variant): bool => $variant->name === MediaVariantName::PostFeed640);

        return $hasFeedSize ? ImageState::Ready : ImageState::Processing;
    }
}
