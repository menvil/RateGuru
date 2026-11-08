<?php

namespace App\Support\Profile;

use App\Models\Post;
use App\Models\PostSave;
use App\Models\User;
use App\Support\Settings\ProjectSettingsManager;

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

        $savedPostsCount = $isOwner && app(ProjectSettingsManager::class)->featureEnabled('show_saved_posts')
            // What the Saved tab lists: a saved post since hidden or deleted
            // by its author is not counted as one the owner can open.
            ? PostSave::query()->where('user_id', $profileUser->id)->whereHas('post', fn ($post) => $post->published())->count()
            : null;

        return new ProfileStatsData(
            publicPostsCount: $publicPostsCount,
            followersCount: $followersCount,
            followingCount: $followingCount,
            savedPostsCount: $savedPostsCount,
        );
    }
}
