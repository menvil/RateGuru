<?php

namespace App\Actions\Comments;

use App\Enums\CommentStatus;
use App\Exceptions\Comments\CannotCommentException;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;

final class AddCommentAction
{
    private const MAX_BODY_LENGTH = 1000;

    public function handle(?User $user, Post $post, string $body): Comment
    {
        if ($user === null) {
            throw CannotCommentException::becauseGuest();
        }

        if (! $user->canComment()) {
            throw CannotCommentException::becauseUserIsNotAllowed();
        }

        if (! $post->canReceiveComments()) {
            throw CannotCommentException::becausePostIsNotPublic();
        }

        $body = trim($body);

        if ($body === '') {
            throw CannotCommentException::becauseBodyIsInvalid('Comment body is required.');
        }

        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            throw CannotCommentException::becauseBodyIsInvalid('Comment body is too long.');
        }

        return Comment::create([
            'user_id' => $user->id,
            'post_id' => $post->id,
            'body' => $body,
            'status' => CommentStatus::Visible,
        ]);
    }
}
