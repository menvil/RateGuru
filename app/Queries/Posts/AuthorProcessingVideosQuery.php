<?php

namespace App\Queries\Posts;

use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * An author's video and GIF posts still waiting for their media: drafts
 * whose upload the media queue has not finished. Shown to the author alone,
 * on their own profile, so a post that is not out yet is not lost from view;
 * one whose processing failed leaves this list, and its author hears of it
 * in their notifications.
 */
final class AuthorProcessingVideosQuery
{
    /** At most this many: an author has at most a couple processing at once. */
    public const int LIMIT = 10;

    /** @return Collection<int, Post> newest first */
    public function forAuthor(User $author): Collection
    {
        return Post::query()
            ->where('user_id', $author->id)
            ->where('status', PostStatus::Draft)
            ->whereHas('imageAsset', fn (Builder $asset) => $asset
                ->where('status', MediaStatus::Processing)
                ->whereIn('media_type', [MediaType::Video, MediaType::Animation]))
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get(['id', 'title', 'image_asset_id', 'created_at']);
    }
}
