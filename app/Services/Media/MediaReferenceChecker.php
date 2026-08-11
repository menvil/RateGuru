<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Whether a MediaAsset is still owned by anything, checked against the only
 * two real foreign-key usages that exist in this app — posts.image_asset_id
 * and users.avatar_asset_id. Deliberately not reflection/schema-driven: a
 * hardcoded, explicit check of the actual references that exist, not a
 * generic "scan every table for this column name" mechanism.
 *
 * Not final: DeleteUserAccountActionMediaLifecycleTest binds an anonymous
 * subclass to simulate a mid-transaction release failure without
 * reflection, matching how MediaStorage's test doubles work elsewhere in
 * this codebase. There is still exactly one real implementation.
 */
class MediaReferenceChecker
{
    public function isReferenced(MediaAsset $asset): bool
    {
        // withTrashed(): a soft-deleted-but-restorable post must still count
        // as referencing its image — the default query scope excludes
        // trashed rows, which would make every soft-deleted post's image
        // look orphaned the instant the post itself is soft-deleted, even
        // though the post (and its image) may still be restored.
        return Post::withTrashed()->where('image_asset_id', $asset->id)->exists()
            || User::where('avatar_asset_id', $asset->id)->exists();
    }

    /**
     * Batched form of isReferenced(), for chunked reporting callers
     * (media:audit) that would otherwise issue two queries per asset. Two
     * queries total, regardless of how many ids are passed.
     *
     * @param  Collection<int, int>  $assetIds
     * @return Collection<int, int> the subset of $assetIds that are
     *                              referenced by something, keyed by value
     *                              so callers can check membership with
     *                              ->has($id)
     */
    public function referencedAssetIds(Collection $assetIds): Collection
    {
        return Post::withTrashed()->whereIn('image_asset_id', $assetIds)->pluck('image_asset_id')
            ->merge(User::whereIn('avatar_asset_id', $assetIds)->pluck('avatar_asset_id'))
            ->unique()
            ->flip();
    }
}
