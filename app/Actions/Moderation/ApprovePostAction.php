<?php

namespace App\Actions\Moderation;

use App\Actions\Moderation\Concerns\LocksAndAuthorizesPostModeration;
use App\Enums\ModerationActionType;
use App\Enums\PostStatus;
use App\Exceptions\Moderation\CannotModeratePostException;
use App\Jobs\NotifyFollowersAboutNewPostJob;
use App\Models\Post;
use App\Models\User;
use App\Notifications\PostApprovedNotification;
use App\Services\Notifications\LifecycleSafeDatabaseNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ApprovePostAction
{
    use LocksAndAuthorizesPostModeration;

    public function __construct(
        private readonly CreateModerationLogAction $createModerationLog,
        private readonly LifecycleSafeDatabaseNotifier $safeNotifier,
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
            [$lockedActor, $locked] = $this->lockAndAuthorizePostModeration($moderator, $post, 'approve', PostStatus::Pending);

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
                moderator: $lockedActor,
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

        if ($post->user_id !== $moderator->id) {
            try {
                // Identity-bearing DB notification: serialized against
                // anonymization, built from the FRESH locked moderator.
                $this->safeNotifier->send(
                    recipientId: (int) $post->user_id,
                    identitySourceId: (int) $moderator->id,
                    notification: fn (User $freshModerator) => new PostApprovedNotification(
                        post: $post,
                        actor: $freshModerator,
                    ),
                );
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
