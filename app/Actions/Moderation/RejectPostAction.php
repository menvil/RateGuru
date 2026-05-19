<?php

namespace App\Actions\Moderation;

use App\Enums\PostStatus;
use App\Exceptions\Moderation\CannotModeratePostException;
use App\Models\Post;
use App\Models\User;

final class RejectPostAction
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
            'status' => PostStatus::Rejected,
            'needs_review' => false,
        ])->save();
    }
}
