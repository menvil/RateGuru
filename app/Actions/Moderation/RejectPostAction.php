<?php

namespace App\Actions\Moderation;

use App\Enums\ModerationActionType;
use App\Enums\PostStatus;
use App\Exceptions\Moderation\CannotModeratePostException;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RejectPostAction
{
    public function __construct(
        private readonly CreateModerationLogAction $createModerationLog,
    ) {}

    public function handle(User $moderator, Post $post, ?string $reason = null): void
    {
        if (! $moderator->isModerator() && ! $moderator->isAdmin()) {
            throw CannotModeratePostException::becauseUserIsNotAllowed();
        }

        if ($post->status !== PostStatus::Pending) {
            throw CannotModeratePostException::becausePostStatusIsInvalid();
        }

        $fromStatus = $post->status;

        // The status change and its audit log must be atomic: a moderated
        // post must never exist without the matching moderation log.
        DB::transaction(function () use ($moderator, $post, $reason, $fromStatus) {
            $persisted = $post->forceFill([
                'status' => PostStatus::Rejected,
                'needs_review' => false,
            ])->save();

            if ($persisted !== true) {
                throw CannotModeratePostException::becausePostStatusIsInvalid();
            }

            $this->createModerationLog->handle(
                moderator: $moderator,
                action: ModerationActionType::RejectPost,
                target: $post,
                reason: $reason,
                metadata: [
                    'from_status' => $fromStatus->value,
                    'to_status' => PostStatus::Rejected->value,
                ],
            );
        });
    }
}
