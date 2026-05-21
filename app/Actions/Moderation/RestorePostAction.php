<?php

namespace App\Actions\Moderation;

use App\Enums\ModerationActionType;
use App\Enums\PostStatus;
use App\Exceptions\Moderation\CannotModeratePostException;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RestorePostAction
{
    public function __construct(
        private readonly CreateModerationLogAction $createModerationLog,
    ) {}

    public function handle(User $moderator, Post $post, ?string $reason = null): void
    {
        if (! $moderator->can('restore', $post)) {
            throw CannotModeratePostException::becauseUserIsNotAllowed();
        }

        // The status check, mutation, and audit log run inside a single
        // transaction with a row lock on the post so a concurrent moderation
        // cannot bypass the state guard between the check and the write.
        DB::transaction(function () use ($moderator, $post, $reason) {
            $locked = $post->newQuery()->lockForUpdate()->find($post->getKey());

            if ($locked === null || $locked->status !== PostStatus::Hidden) {
                throw CannotModeratePostException::becausePostStatusIsInvalid();
            }

            $fromStatus = $locked->status;

            $persisted = $locked->forceFill([
                'status' => PostStatus::Published,
                'published_at' => $locked->published_at ?? now(),
                'needs_review' => false,
            ])->save();

            if ($persisted !== true) {
                throw CannotModeratePostException::becausePostStatusIsInvalid();
            }

            $this->createModerationLog->handle(
                moderator: $moderator,
                action: ModerationActionType::RestorePost,
                target: $locked,
                reason: $reason,
                metadata: [
                    'from_status' => $fromStatus->value,
                    'to_status' => PostStatus::Published->value,
                ],
            );

            $post->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
