<?php

namespace App\Actions\Posts;

use App\Enums\PostStatus;
use App\Exceptions\Posts\CannotDeletePostException;
use App\Models\Post;
use App\Models\User;

final class DeletePostAction
{
    public function handle(User $user, Post $post): void
    {
        if ((int) $post->user_id !== (int) $user->id && ! $user->isAdmin() && ! $user->isModerator()) {
            throw CannotDeletePostException::becauseUserIsNotAllowed();
        }

        $post->forceFill(['status' => PostStatus::Deleted])->save();
        $post->delete();
    }
}
