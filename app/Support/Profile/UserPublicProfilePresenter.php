<?php

namespace App\Support\Profile;

use App\Models\User;

final class UserPublicProfilePresenter
{
    public function forUser(User $user): UserPublicProfile
    {
        return new UserPublicProfile(
            id: $user->id,
            username: $user->public_username,
            displayName: $user->resolved_display_name,
            avatarUrl: $user->resolved_avatar_url,
            bio: $user->bio,
            websiteUrl: $user->profile_website_url,
            joinedAt: $user->created_at,
        );
    }
}
