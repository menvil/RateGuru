<?php

namespace App\Actions\Comments;

use App\Actions\Comments\Concerns\RefreshesPostCommentsCount;
use App\Actions\Moderation\CreateModerationLogAction;
use App\Enums\CommentStatus;
use App\Enums\ModerationActionType;
use App\Exceptions\Comments\CannotRestoreCommentException;
use App\Models\Comment;
use App\Models\Concerns\LocksActorForWrite;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class RestoreCommentAction
{
    use LocksActorForWrite;
    use RefreshesPostCommentsCount;

    public function __construct(
        private readonly CreateModerationLogAction $createModerationLog,
    ) {}

    public function handle(User $moderator, Comment $comment, ?string $reason = null): void
    {
        if (! $moderator->can('restore', $comment)) {
            throw CannotRestoreCommentException::becauseUserIsNotAllowed();
        }

        // The status check, mutation, counter refresh, and audit log run
        // inside a single transaction with a row lock on the comment so two
        // concurrent restores cannot both observe the Hidden state and emit
        // duplicate RestoreComment logs. Mirrors HidePostAction.
        DB::transaction(function () use ($moderator, $comment, $reason) {
            // Lock order: Actor User -> Comment; the moderator instance may
            // be stale, only the locked rows are authoritative.
            $lockedActor = $this->lockActor($moderator);

            $locked = $comment->newQuery()->lockForUpdate()->find($comment->getKey());

            if ($locked === null || $locked->status !== CommentStatus::Hidden) {
                throw CannotRestoreCommentException::becauseCommentStatusIsInvalid();
            }

            // A finalized moderation removal never returns through the
            // normal lifecycle.
            if ($locked->moderation_removed_at !== null) {
                throw CannotRestoreCommentException::becauseCommentStatusIsInvalid();
            }

            if ($lockedActor === null || ! Gate::forUser($lockedActor)->allows('restore', $locked)) {
                throw CannotRestoreCommentException::becauseUserIsNotAllowed();
            }

            $post = $locked->post;
            $fromStatus = $locked->status;

            $locked->forceFill(['status' => CommentStatus::Visible])->save();

            $this->refreshCommentsCount($post);

            $this->createModerationLog->handle(
                moderator: $lockedActor,
                action: ModerationActionType::RestoreComment,
                target: $locked,
                reason: $reason,
                metadata: [
                    'from_status' => $fromStatus->value,
                    'to_status' => CommentStatus::Visible->value,
                ],
            );

            $comment->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
