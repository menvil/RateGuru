<?php

namespace App\Support\Profile;

use App\Models\Post;
use App\Models\PostSave;
use App\Models\User;

final class ProfileStats
{
    public function forUser(User $profileUser, ?User $viewer = null): ProfileStatsData
    {
        $isOwner = $viewer !== null && $viewer->id === $profileUser->id;

        $publicPostsCount = Post::query()
            ->published()
            ->where('user_id', $profileUser->id)
            ->count();

        $followersCount = $profileUser->follower_relations_count ?? $profileUser->followerRelations()->count();
        $followingCount = $profileUser->following_relations_count ?? $profileUser->followingRelations()->count();

        $savedPostsCount = $isOwner
            ? PostSave::query()->where('user_id', $profileUser->id)->count()
            : null;

        return new ProfileStatsData(
            publicPostsCount: $publicPostsCount,
            followersCount: $followersCount,
            followingCount: $followingCount,
            savedPostsCount: $savedPostsCount,
        );
    }
}
