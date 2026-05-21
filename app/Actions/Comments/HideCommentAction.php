<?php

namespace App\Actions\Comments;

use App\Actions\Comments\Concerns\RefreshesPostCommentsCount;
use App\Actions\Moderation\CreateModerationLogAction;
use App\Enums\CommentStatus;
use App\Enums\ModerationActionType;
use App\Exceptions\Comments\CannotHideCommentException;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class HideCommentAction
{
    use RefreshesPostCommentsCount;

    public function __construct(
        private readonly CreateModerationLogAction $createModerationLog,
    ) {}

    public function handle(User $user, Comment $comment, ?string $reason = null): void
    {
        if (! $user->can('hide', $comment)) {
            throw CannotHideCommentException::becauseUserIsNotAllowed();
        }

        // The status check, mutation, counter refresh, and audit log run
        // inside a single transaction with a row lock on the comment so two
        // concurrent hides cannot both pass an idempotency check and emit
        // duplicate moderation logs. Mirrors HidePostAction.
        DB::transaction(function () use ($user, $comment, $reason) {
            $locked = $comment->newQuery()->lockForUpdate()->find($comment->getKey());

            if ($locked === null || $locked->status === CommentStatus::Hidden) {
                return;
            }

            $post = $locked->post;
            $fromStatus = $locked->status;

            $locked->forceFill(['status' => CommentStatus::Hidden])->save();

            $this->refreshCommentsCount($post);

            $this->createModerationLog->handle(
                moderator: $user,
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
