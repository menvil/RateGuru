<?php

namespace App\Actions\Comments;

use App\Actions\Comments\Concerns\RefreshesPostCommentsCount;
use App\Exceptions\Comments\CannotDeleteCommentException;
use App\Models\Comment;
use App\Models\Concerns\LocksActorForWrite;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class DeleteCommentAction
{
    use LocksActorForWrite;
    use RefreshesPostCommentsCount;

    public function handle(User $user, Comment $comment): void
    {
        // Everything — including authorization — runs against a fresh row
        // locked inside the transaction: the caller's instance may be stale
        // by the time this executes, and a delete racing another delete or
        // a moderation action must behave deterministically.
        DB::transaction(function () use ($user, $comment): void {
            // Lock order: Actor User -> Comment; authorization runs against
            // the locked actor so a just-sanctioned author cannot finish
            // the deletion.
            $lockedActor = $this->lockActor($user);

            $locked = Comment::withTrashed()
                ->whereKey($comment->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return;
            }

            if ($lockedActor === null || ! Gate::forUser($lockedActor)->allows('delete', $locked)) {
                throw CannotDeleteCommentException::becauseUserIsNotAllowed();
            }

            // A finalized moderation removal must never cross into ordinary
            // author cleanup: the row is moderation evidence under the
            // moderation retention policy. Hidden-but-not-finalized keeps
            // its PR-D author-delete behavior.
            if ($locked->isModerationRemovalFinalized()) {
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
