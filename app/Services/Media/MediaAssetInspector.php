<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Models\Post;
use App\Models\User;

/**
 * Assembles one MediaAsset's full inspector view — master details, variant
 * existence, references, lifecycle state. Every exists() call here is
 * scoped to the single asset being inspected (never a sweep), which is what
 * makes this safe to call from a Filament page request unlike
 * MediaAuditService::run().
 */
final class MediaAssetInspector
{
    public function __construct(
        private readonly MediaStorage $storage,
        private readonly MediaReferenceChecker $referenceChecker,
        private readonly MediaLifecycleService $lifecycle,
    ) {}

    public function inspect(MediaAsset $asset): MediaAssetInspection
    {
        $asset->loadMissing('variants');

        $variants = $asset->variants->map(fn (MediaVariant $variant): array => [
            'variant' => $variant,
            'exists' => $this->storage->exists(new MediaLocation($variant->disk, $variant->path)),
        ]);

        $graceExpired = $this->lifecycle->isGraceExpired($asset);

        return new MediaAssetInspection(
            asset: $asset,
            masterExists: $this->storage->exists(new MediaLocation($asset->disk, $asset->path)),
            referenced: $this->referenceChecker->isReferenced($asset),
            variants: $variants,
            referencingPosts: Post::withTrashed()->where('image_asset_id', $asset->id)->get(),
            referencingUsers: User::where('avatar_asset_id', $asset->id)->get(),
            graceExpired: $graceExpired,
            purgeable: $this->lifecycle->isPurgeable($asset),
            purgeableAt: $asset->deleted_at?->clone()->addDays((int) config('media.lifecycle.purge_grace_days')),
        );
    }
}
