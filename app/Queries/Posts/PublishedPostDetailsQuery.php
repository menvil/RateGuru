<?php

namespace App\Queries\Posts;

use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;

final class PublishedPostDetailsQuery
{
    public function find(int $postId): ?Post
    {
        return $this->query()->find($postId);
    }

    public function findOrFail(int $postId): Post
    {
        return $this->query()->findOrFail($postId);
    }

    /** Whether the post is one its own page would show, without loading it. */
    public function isPublished(int $postId): bool
    {
        return Post::query()->published()->whereKey($postId)->exists();
    }

    /** @return Builder<Post> */
    private function query(): Builder
    {
        return Post::query()
            ->published()
            ->with(['user', 'tags', 'category', 'imageAsset.variants', 'user.avatarAsset.variants']);
    }
}
