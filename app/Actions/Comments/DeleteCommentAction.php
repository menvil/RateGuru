<?php

namespace App\Actions\Comments;

use App\Models\Comment;
use App\Models\User;

final class DeleteCommentAction
{
    public function handle(User $user, Comment $comment): void
    {
        throw new \LogicException('Not implemented yet.');
    }
}
