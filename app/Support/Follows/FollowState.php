<?php

namespace App\Support\Follows;

use App\Models\Follow;
use App\Models\User;

final class FollowState
{
    /**
     * @param  iterable<User>  $authors
     */
    public function forViewerAndAuthors(?User $viewer, iterable $authors): FollowStateMap
    {
        if ($viewer === null) {
            return new FollowStateMap([]);
        }

        $authorIds = collect($authors)->pluck('id')->all();

        if (empty($authorIds)) {
            return new FollowStateMap([]);
        }

        $followedIds = Follow::query()
            ->where('follower_id', $viewer->id)
            ->whereIn('author_id', $authorIds)
            ->pluck('author_id')
            ->flip()
            ->map(fn () => true)
            ->all();

        return new FollowStateMap($followedIds);
    }

    public function isFollowing(?User $viewer, User $author): bool
    {
        if ($viewer === null) {
            return false;
        }

        return Follow::query()
            ->where('follower_id', $viewer->id)
            ->where('author_id', $author->id)
            ->exists();
    }
}
