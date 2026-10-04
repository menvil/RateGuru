<?php

namespace App\Actions\Comments;

use App\Actions\Comments\Concerns\RefreshesPostCommentsCount;
use App\Actions\Moderation\CreateModerationLogAction;
use App\Enums\CommentStatus;
use App\Enums\ModerationActionType;
use App\Exceptions\Comments\CannotHideCommentException;
use App\Models\Comment;
use App\Models\Concerns\LocksActorForWrite;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class HideCommentAction
{
    use LocksActorForWrite;
    use RefreshesPostCommentsCount;

    public function __construct(
        private readonly CreateModerationLogAction $createModerationLog,
    ) {}

    public function handle(User $user, Comment $comment, ?string $reason = null): void
    {
        if (! Gate::forUser($user)->allows('moderate-content')) {
            throw CannotHideCommentException::becauseUserIsNotAllowed();
        }

        // The status check, mutation, counter refresh, and audit log run
        // inside a single transaction with a row lock on the comment so two
        // concurrent hides cannot both pass an idempotency check and emit
        // duplicate moderation logs. Mirrors HidePostAction.
        DB::transaction(function () use ($user, $comment, $reason) {
            // Lock order: Actor User -> Post -> Comment; the moderator
            // instance may be stale, only the locked rows are
            // authoritative. The post lock is ordering only — moderation
            // deliberately keeps working under Hidden/deleted parents.
            $lockedActor = $this->lockActor($user);

            if ($lockedActor === null || ! Gate::forUser($lockedActor)->allows('moderate-content')) {
                throw CannotHideCommentException::becauseUserIsNotAllowed();
            }

            $lockedPost = Post::withTrashed()
                ->whereKey($comment->post_id)
                ->lockForUpdate()
                ->first();

            $locked = $comment->newQuery()->lockForUpdate()->find($comment->getKey());

            if ($lockedPost === null || $locked === null || $locked->status === CommentStatus::Hidden) {
                return;
            }

            $fromStatus = $locked->status;

            $locked->forceFill(['status' => CommentStatus::Hidden])->save();

            $this->refreshCommentsCount($lockedPost);

            $this->createModerationLog->handle(
                moderator: $lockedActor,
                action: ModerationActionType::HideComment,
                target: $locked,
                reason: $reason,
                metadata: [
                    'from_status' => $fromStatus->value,
                    'to_status' => CommentStatus::Hidden->value,
                ],
            );

            $comment->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
