<?php

namespace App\Support\SavedPosts;

use App\Models\Post;

final class SavedPostStateMap
{
    /** @param array<int, true> $savedPostIds */
    public function __construct(private readonly array $savedPostIds) {}

    public function isSaved(Post $post): bool
    {
        return isset($this->savedPostIds[$post->id]);
    }
}
