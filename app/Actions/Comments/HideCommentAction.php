<?php

namespace App\Actions\Comments;

use App\Actions\Comments\Concerns\RefreshesPostCommentsCount;
use App\Enums\CommentStatus;
use App\Exceptions\Comments\CannotHideCommentException;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class HideCommentAction
{
    use RefreshesPostCommentsCount;

    public function handle(User $user, Comment $comment): void
    {
        if (! $user->isModerator() && ! $user->isAdmin()) {
            throw CannotHideCommentException::becauseUserIsNotAllowed();
        }

        if ($comment->status === CommentStatus::Hidden) {
            return;
        }

        DB::transaction(function () use ($comment) {
            $post = $comment->post;

            $comment->update(['status' => CommentStatus::Hidden]);

            $this->refreshCommentsCount($post);
        });
    }
}
