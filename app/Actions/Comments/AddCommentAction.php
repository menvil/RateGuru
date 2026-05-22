<?php

namespace App\Actions\Comments;

use App\Actions\Comments\Concerns\RefreshesPostCommentsCount;
use App\Enums\CommentStatus;
use App\Exceptions\Comments\CannotCommentException;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use App\Notifications\PostCommentedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AddCommentAction
{
    use RefreshesPostCommentsCount;

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

        $comment = DB::transaction(function () use ($user, $post, $body) {
            $comment = Comment::create([
                'user_id' => $user->id,
                'post_id' => $post->id,
                'body' => $body,
                'status' => CommentStatus::Visible,
            ]);

            $this->refreshCommentsCount($post);

            return $comment;
        });

        if ($post->user_id !== $user->id) {
            $post->loadMissing('user');

            try {
                $post->user?->notify(new PostCommentedNotification(
                    post: $post,
                    comment: $comment,
                    actor: $user,
                ));
            } catch (Throwable $exception) {
                report($exception);

                Log::error('Failed to send post commented notification.', [
                    'post_id' => $post->id,
                    'comment_id' => $comment->id,
                    'actor_id' => $user->id,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        return $comment;
    }
}
