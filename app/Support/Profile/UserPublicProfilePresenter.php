<?php

namespace App\Support\Profile;

use App\Models\User;

final class UserPublicProfilePresenter
{
    public function forUser(User $user): UserPublicProfile
    {
        return new UserPublicProfile(
            id: $user->id,
            username: $user->username,
            displayName: $this->resolveDisplayName($user),
            avatarUrl: $user->resolved_avatar_url,
            bio: $user->bio,
            websiteUrl: $user->profile_website_url,
            joinedAt: $user->created_at,
        );
    }

    private function resolveDisplayName(User $user): string
    {
        return $user->display_name
            ?: ($user->name ?: $user->username);
    }
}
