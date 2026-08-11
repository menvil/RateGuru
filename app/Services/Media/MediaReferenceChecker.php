<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\User;

/**
 * Whether a MediaAsset is still owned by anything, checked against the only
 * two real foreign-key usages that exist in this app — posts.image_asset_id
 * and users.avatar_asset_id. Deliberately not reflection/schema-driven: a
 * hardcoded, explicit check of the actual references that exist, not a
 * generic "scan every table for this column name" mechanism.
 */
final class MediaReferenceChecker
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
}
