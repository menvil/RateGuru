<?php

namespace App\Actions\Comments;

use App\Actions\Comments\Concerns\RefreshesPostCommentsCount;
use App\Exceptions\Comments\CannotDeleteCommentException;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DeleteCommentAction
{
    use RefreshesPostCommentsCount;

    public function handle(User $user, Comment $comment): void
    {
        if (! $user->can('delete', $comment)) {
            throw CannotDeleteCommentException::becauseUserIsNotAllowed();
        }

        DB::transaction(function () use ($comment) {
            $post = $comment->post;

            $comment->delete();

            $this->refreshCommentsCount($post);
        });
    }
}
