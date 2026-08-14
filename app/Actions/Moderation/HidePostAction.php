<?php

namespace App\Actions\Moderation;

use App\Enums\ModerationActionType;
use App\Enums\PostStatus;
use App\Exceptions\Moderation\CannotModeratePostException;
use App\Models\Concerns\LocksActorForWrite;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class HidePostAction
{
    use LocksActorForWrite;

    public function __construct(
        private readonly CreateModerationLogAction $createModerationLog,
    ) {}

    public function handle(User $moderator, Post $post, ?string $reason = null): void
    {
        if (! Gate::forUser($moderator)->allows('moderate-content')) {
            throw CannotModeratePostException::becauseUserIsNotAllowed();
        }

        // The status check, mutation, and audit log run inside a single
        // transaction with a row lock on the post so a concurrent moderation
        // cannot bypass the state guard between the check and the write.
        DB::transaction(function () use ($moderator, $post, $reason) {
            // Lock order: Actor User -> Post. The moderator instance may be
            // stale — a sanction can commit between the pre-check and this
            // write; only the locked rows are authoritative.
            $lockedActor = $this->lockActor($moderator);

            $locked = $post->newQuery()->lockForUpdate()->find($post->getKey());

            if ($locked === null || $locked->status !== PostStatus::Published) {
                throw CannotModeratePostException::becausePostStatusIsInvalid();
            }

            if ($lockedActor === null || ! Gate::forUser($lockedActor)->allows('moderate-content')) {
                throw CannotModeratePostException::becauseUserIsNotAllowed();
            }

            $fromStatus = $locked->status;

            $persisted = $locked->forceFill([
                'status' => PostStatus::Hidden,
            ])->save();

            if ($persisted !== true) {
                throw CannotModeratePostException::becausePostStatusIsInvalid();
            }

            $this->createModerationLog->handle(
                moderator: $lockedActor,
                action: ModerationActionType::HidePost,
                target: $locked,
                reason: $reason,
                metadata: [
                    'from_status' => $fromStatus->value,
                    'to_status' => PostStatus::Hidden->value,
                ],
            );

            $post->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
