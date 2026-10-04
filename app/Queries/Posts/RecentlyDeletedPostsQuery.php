<?php

namespace App\Queries\Posts;

use App\Contracts\Persistence\StablePaginationBoundary;
use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The owner's own author-deleted posts for the Recently Deleted surface
 * (docs/architecture/post-lifecycle.md). Only the well-formed shape
 * (soft-deleted + status Deleted) is listed; legacy soft-deleted rows with
 * another status are not restorable and stay out.
 */
final class RecentlyDeletedPostsQuery implements StablePaginationBoundary
{
    public function forOwner(User $owner, int $perPage = 20): LengthAwarePaginator
    {
        return Post::onlyTrashed()
            ->where('user_id', $owner->id)
            ->where('status', PostStatus::Deleted)
            ->orderByDesc('deleted_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
