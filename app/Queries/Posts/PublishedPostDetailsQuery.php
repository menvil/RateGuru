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

    /** @return Builder<Post> */
    private function query(): Builder
    {
        return Post::query()
            ->published()
            ->with(['user', 'tags', 'category']);
    }
}
