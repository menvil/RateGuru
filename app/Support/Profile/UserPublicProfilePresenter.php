<?php

namespace App\Support\Profile;

use App\Models\User;
use Illuminate\Support\Facades\Storage;

final class UserPublicProfilePresenter
{
    public function forUser(User $user): UserPublicProfile
    {
        return new UserPublicProfile(
            id: $user->id,
            username: $user->username,
            displayName: $this->resolveDisplayName($user),
            avatarUrl: $this->resolveAvatarUrl($user),
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

    private function resolveAvatarUrl(User $user): ?string
    {
        if ($user->avatar_path) {
            return Storage::disk('public')->url($user->avatar_path);
        }

        return $user->avatar_url;
    }
}
