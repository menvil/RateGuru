<?php

namespace App\Actions\Comments;

use App\Models\Comment;
use App\Models\Post;
use App\Models\User;

final class AddCommentAction
{
    public function handle(?User $user, Post $post, string $body): Comment
    {
        throw new \LogicException('Not implemented yet.');
    }
}
