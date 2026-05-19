<?php

namespace App\Actions\Moderation;

use App\Enums\PostStatus;
use App\Exceptions\Moderation\CannotModeratePostException;
use App\Models\Post;
use App\Models\User;

final class ApprovePostAction
{
    public function handle(User $moderator, Post $post, ?string $reason = null): void
    {
        if (! $moderator->isModerator() && ! $moderator->isAdmin()) {
            throw CannotModeratePostException::becauseUserIsNotAllowed();
        }

        if ($post->status !== PostStatus::Pending) {
            throw CannotModeratePostException::becausePostStatusIsInvalid();
        }

        $post->forceFill([
            'status' => PostStatus::Published,
            'published_at' => $post->published_at ?? now(),
            'needs_review' => false,
        ])->save();
    }
}
