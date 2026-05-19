<?php

namespace App\Actions\Moderation;

use App\Enums\PostStatus;
use App\Exceptions\Moderation\CannotModeratePostException;
use App\Models\Post;
use App\Models\User;

final class HidePostAction
{
    public function handle(User $moderator, Post $post, ?string $reason = null): void
    {
        if (! $moderator->isModerator() && ! $moderator->isAdmin()) {
            throw CannotModeratePostException::becauseUserIsNotAllowed();
        }

        if ($post->status !== PostStatus::Published) {
            throw CannotModeratePostException::becausePostStatusIsInvalid();
        }

        $post->forceFill([
            'status' => PostStatus::Hidden,
        ])->save();
    }
}
