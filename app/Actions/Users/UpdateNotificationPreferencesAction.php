<?php

namespace App\Actions\Users;

use App\Models\User;

final class UpdateNotificationPreferencesAction
{
    public function handle(User $user, bool $notifyFollowedAuthorPosts): void
    {
        $user->update([
            'notify_followed_author_posts' => $notifyFollowedAuthorPosts,
        ]);
    }
}
