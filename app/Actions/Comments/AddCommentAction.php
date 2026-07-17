<?php

namespace App\Actions\Comments;

use App\Actions\Comments\Concerns\RefreshesPostCommentsCount;
use App\Actions\Ranking\RecalculatePostScoreAction;
use App\Enums\CommentStatus;
use App\Exceptions\Abuse\RateLimitExceededException;
use App\Exceptions\Comments\CannotCommentException;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use App\Notifications\PostCommentedNotification;
use App\Support\AbuseGuards\ActionRateLimiter;
use App\Support\AbuseGuards\RateLimitKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AddCommentAction
{
    use RefreshesPostCommentsCount;

    private const MAX_BODY_LENGTH = 1000;

    public function __construct(
        private readonly RecalculatePostScoreAction $recalculatePostScore,
        private readonly ActionRateLimiter $rateLimiter,
    ) {}

    public function handle(?User $user, Post $post, string $body, ?Comment $parent = null): Comment
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

        try {
            $this->rateLimiter->hitOrFail(
                key: RateLimitKey::userAction('comment', $user),
                maxAttempts: (int) config('rate_limits.comment.max_attempts'),
                decaySeconds: (int) config('rate_limits.comment.decay_seconds'),
                message: 'You are commenting too quickly. Please try again later.',
            );
        } catch (RateLimitExceededException $e) {
            throw CannotCommentException::becauseRateLimited($e->getMessage());
        }

        $body = trim($body);

        if ($body === '') {
            throw CannotCommentException::becauseBodyIsInvalid('Comment body is required.');
        }

        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            throw CannotCommentException::becauseBodyIsInvalid('Comment body is too long.');
        }

        if ($parent !== null) {
            if (! $parent->exists) {
                throw CannotCommentException::becauseBodyIsInvalid('Reply target is unavailable.');
            }

            if ((int) $parent->post_id !== (int) $post->id || $parent->parent_id !== null) {
                throw CannotCommentException::becauseBodyIsInvalid('Reply target is unavailable.');
            }
        }

        $comment = DB::transaction(function () use ($user, $post, $body, $parent) {
            $comment = Comment::create([
                'user_id' => $user->id,
                'post_id' => $post->id,
                'parent_id' => $parent?->id,
                'body' => $body,
                'status' => CommentStatus::Visible,
            ]);

            $this->refreshCommentsCount($post);
            $this->recalculatePostScore->handle($post->refresh());

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
