<?php

namespace App\Queries;

use App\Models\Post;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

final class UserPublicPostsQuery
{
    public function forProfile(User $user, int $perPage = 12): LengthAwarePaginator
    {
        return Post::query()
            ->published()
            ->where('user_id', $user->id)
            ->with(['user', 'tags'])
            ->latest('published_at')
            ->paginate($perPage);
    }
}
