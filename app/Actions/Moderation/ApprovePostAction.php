<?php

namespace App\Actions\Moderation;

use App\Enums\ModerationActionType;
use App\Jobs\NotifyFollowersAboutNewPostJob;
use App\Enums\PostStatus;
use App\Exceptions\Moderation\CannotModeratePostException;
use App\Models\Post;
use App\Models\User;
use App\Notifications\PostApprovedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ApprovePostAction
{
    public function __construct(
        private readonly CreateModerationLogAction $createModerationLog,
    ) {}

    public function handle(User $moderator, Post $post, ?string $reason = null): void
    {
        if (! $moderator->can('approve', $post)) {
            throw CannotModeratePostException::becauseUserIsNotAllowed();
        }

        // The status check, mutation, and audit log run inside a single
        // transaction with a row lock on the post so a concurrent moderation
        // cannot bypass the state guard between the check and the write.
        DB::transaction(function () use ($moderator, $post, $reason) {
            $locked = $post->newQuery()->lockForUpdate()->find($post->getKey());

            if ($locked === null || $locked->status !== PostStatus::Pending) {
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
                action: ModerationActionType::ApprovePost,
                target: $locked,
                reason: $reason,
                metadata: [
                    'from_status' => $fromStatus->value,
                    'to_status' => PostStatus::Published->value,
                ],
            );

            $post->setRawAttributes($locked->getAttributes(), true);
        });

        $post->loadMissing('user');

        if ($post->user_id !== $moderator->id) {
            try {
                $post->user?->notify(new PostApprovedNotification(
                    post: $post,
                    actor: $moderator,
                ));
            } catch (Throwable $exception) {
                report($exception);

                Log::error('Failed to send post approved notification.', [
                    'post_id' => $post->id,
                    'moderator_id' => $moderator->id,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        try {
            NotifyFollowersAboutNewPostJob::dispatch($post->id);
        } catch (Throwable $exception) {
            report($exception);

            Log::error('Failed to dispatch follower notification job.', [
                'post_id' => $post->id,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
