<?php

namespace App\Actions\Comments;

use App\Actions\Comments\Concerns\RefreshesPostCommentsCount;
use App\Actions\Moderation\CreateModerationLogAction;
use App\Enums\CommentStatus;
use App\Enums\ModerationActionType;
use App\Exceptions\Comments\CannotRestoreCommentException;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RestoreCommentAction
{
    use RefreshesPostCommentsCount;

    public function __construct(
        private readonly CreateModerationLogAction $createModerationLog,
    ) {}

    public function handle(User $moderator, Comment $comment, ?string $reason = null): void
    {
        if (! $moderator->isModerator() && ! $moderator->isAdmin()) {
            throw CannotRestoreCommentException::becauseUserIsNotAllowed();
        }

        if ($comment->status !== CommentStatus::Hidden) {
            throw CannotRestoreCommentException::becauseCommentStatusIsInvalid();
        }

        DB::transaction(function () use ($moderator, $comment, $reason) {
            $post = $comment->post;
            $fromStatus = $comment->status;

            $comment->forceFill([
                'status' => CommentStatus::Visible,
            ])->save();

            $this->refreshCommentsCount($post);

            $this->createModerationLog->handle(
                moderator: $moderator,
                action: ModerationActionType::RestoreComment,
                target: $comment,
                reason: $reason,
                metadata: [
                    'from_status' => $fromStatus->value,
                    'to_status' => CommentStatus::Visible->value,
                ],
            );
        });
    }
}
