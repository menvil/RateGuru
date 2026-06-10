<?php

namespace App\Support\SavedPosts;

use App\Models\Post;
use App\Models\PostSave;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final class SavedPostState
{
    /**
     * @param  Collection<int, Post>|iterable<Post>  $posts
     */
    public function forUserAndPosts(?User $user, iterable $posts): SavedPostStateMap
    {
        if ($user === null) {
            return new SavedPostStateMap([]);
        }

        $postIds = collect($posts)->pluck('id')->all();

        if (empty($postIds)) {
            return new SavedPostStateMap([]);
        }

        $savedIds = PostSave::query()
            ->where('user_id', $user->id)
            ->whereIn('post_id', $postIds)
            ->pluck('post_id')
            ->flip()
            ->map(fn () => true)
            ->all();

        return new SavedPostStateMap($savedIds);
    }

    public function forUserAndPost(?User $user, Post $post): bool
    {
        if ($user === null) {
            return false;
        }

        return PostSave::query()
            ->where('user_id', $user->id)
            ->where('post_id', $post->id)
            ->exists();
    }
}
