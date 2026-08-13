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
        // Everything — including authorization — runs against a fresh row
        // locked inside the transaction: the caller's instance may be stale
        // by the time this executes, and a delete racing another delete or
        // a moderation action must behave deterministically.
        DB::transaction(function () use ($user, $comment): void {
            $locked = Comment::withTrashed()
                ->whereKey($comment->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return;
            }

            if (! $user->can('delete', $locked)) {
                throw CannotDeleteCommentException::becauseUserIsNotAllowed();
            }

            // Retry-safe: a second delete of an already author-deleted
            // comment is a no-op — no second soft-delete, no double
            // counter refresh.
            if ($locked->trashed()) {
                $comment->setRawAttributes($locked->getAttributes(), true);

                return;
            }

            $post = $locked->post;

            $locked->delete();

            $this->refreshCommentsCount($post);

            $comment->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
