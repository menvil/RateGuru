<?php

namespace App\Queries\SavedPosts;

use App\Contracts\Persistence\StablePaginationBoundary;
use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class SavedPostsQuery implements StablePaginationBoundary
{
    public function forUser(User $user, int $perPage = 12): LengthAwarePaginator
    {
        return Post::query()
            ->join('post_saves', 'posts.id', '=', 'post_saves.post_id')
            ->where('post_saves.user_id', $user->id)
            ->where('posts.status', PostStatus::Published)
            ->whereNull('posts.deleted_at')
            ->with(['user', 'tags'])
            ->select('posts.*', 'post_saves.created_at as saved_at')
            ->orderBy('post_saves.created_at', 'desc')
            ->orderByDesc('posts.id')
            ->paginate($perPage);
    }
}
