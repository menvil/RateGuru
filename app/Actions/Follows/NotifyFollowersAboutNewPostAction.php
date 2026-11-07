<?php

namespace App\Actions\Follows;

use App\Enums\PostStatus;
use App\Exceptions\Follows\FollowerNotificationsNotDeliveredException;
use App\Models\Follow;
use App\Models\Post;
use App\Models\User;
use App\Notifications\FollowedAuthorPostedNotification;
use App\Services\Notifications\LifecycleSafeDatabaseNotifier;
use App\Support\Observability\DomainLogger;
use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

final class NotifyFollowersAboutNewPostAction
{
    public function __construct(
        private readonly DomainLogger $logger,
        private readonly LifecycleSafeDatabaseNotifier $safeNotifier,
        private readonly ProjectSettingsManager $settings,
    ) {}

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, User>  $followers
     * @return Collection<int, int>
     */
    private function getAlreadyNotifiedFollowerIds(\Illuminate\Database\Eloquent\Collection $followers, Post $post): Collection
    {
        if ($followers->isEmpty()) {
            return collect();
        }

        return DatabaseNotification::query()
            ->where('type', FollowedAuthorPostedNotification::class)
            ->where('notifiable_type', (new User)->getMorphClass())
            ->whereIn('notifiable_id', $followers->pluck('id')->all())
            ->where('data->post_id', $post->id)
            ->pluck('notifiable_id');
    }

    public function handle(Post $post): void
    {
        if ($post->status !== PostStatus::Published) {
            return;
        }

        // Following is a feature the project can switch off, and with it
        // goes what following brings: no notification of a followed author's
        // post (docs/follows/follow-authors.md), the follows kept for when it
        // is switched on again.
        if (! $this->settings->featureEnabled('show_follow_buttons')) {
            return;
        }

        $author = $post->user;

        if ($author === null) {
            return;
        }

        $sentCount = 0;
        $skippedCount = 0;
        $failedCount = 0;

        User::query()
            ->whereIn(
                'id',
                Follow::query()
                    ->select('follower_id')
                    ->where('author_id', $author->id),
            )
            ->where('id', '!=', $author->id)
            ->where('notify_followed_author_posts', true)
            // By id, not by offset: a follower who turns notifications off or
            // unfollows mid-send leaves the filter, and an offset would then
            // step over someone who never got the notification.
            ->chunkById(500, function ($followers) use ($post, $author, &$sentCount, &$skippedCount, &$failedCount): void {
                $alreadyNotifiedIds = $this->getAlreadyNotifiedFollowerIds($followers, $post);

                foreach ($followers as $follower) {
                    if ($alreadyNotifiedIds->contains($follower->id)) {
                        $skippedCount++;
                        $this->logger->info('notifications.followed_author_posted.duplicate_skipped', [
                            'post_id' => $post->id,
                            'author_id' => $author->id,
                            'follower_id' => $follower->id,
                        ]);

                        continue;
                    }

                    try {
                        // Per-recipient safe transaction: author identity
                        // comes from the fresh locked author row, and a
                        // Deleted author or follower creates nothing.
                        $sent = $this->safeNotifier->send(
                            recipientId: (int) $follower->id,
                            identitySourceId: (int) $author->id,
                            notification: fn (User $freshAuthor) => new FollowedAuthorPostedNotification($post, $freshAuthor),
                        );

                        if ($sent) {
                            $sentCount++;
                        } else {
                            $skippedCount++;
                        }
                    } catch (Throwable $exception) {
                        $failedCount++;
                        report($exception);

                        $this->logger->error('notifications.followed_author_posted.failed', [
                            'post_id' => $post->id,
                            'author_id' => $author->id,
                            'follower_id' => $follower->id,
                            'error_class' => get_class($exception),
                        ]);

                        Log::error('Failed to send followed author posted notification.', [
                            'post_id' => $post->id,
                            'follower_id' => $follower->id,
                            'exception' => $exception->getMessage(),
                        ]);
                    }
                }
            });

        if ($sentCount > 0) {
            $this->logger->info('notifications.followed_author_posted.sent', [
                'post_id' => $post->id,
                'author_id' => $author->id,
                'recipient_count' => $sentCount,
                'skipped_count' => $skippedCount,
            ]);
        }

        // Only now, so everyone else was notified first: the retry skips them
        // and tries again only the ones this run missed.
        if ($failedCount > 0) {
            throw FollowerNotificationsNotDeliveredException::forPost((int) $post->id, $failedCount);
        }
    }
}
