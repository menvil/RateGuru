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
        if (! $user->isModerator() && ! $user->isAdmin()) {
            throw CannotHideCommentException::becauseUserIsNotAllowed();
        }

        if ($comment->status === CommentStatus::Hidden) {
            return;
        }

        DB::transaction(function () use ($user, $comment, $reason) {
            $post = $comment->post;
            $fromStatus = $comment->status;

            $comment->update(['status' => CommentStatus::Hidden]);

            $this->refreshCommentsCount($post);

            $this->createModerationLog->handle(
                moderator: $user,
                action: ModerationActionType::HideComment,
                target: $comment,
                reason: $reason,
                metadata: [
                    'from_status' => $fromStatus->value,
                    'to_status' => CommentStatus::Hidden->value,
                ],
            );
        });
    }
}
