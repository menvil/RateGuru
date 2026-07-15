<?php

namespace App\Actions\Follows;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;
use App\Notifications\FollowedAuthorPostedNotification;
use App\Support\Observability\DomainLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class NotifyFollowersAboutNewPostAction
{
    public function __construct(private readonly DomainLogger $logger) {}

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, User>  $followers
     * @return Collection<int, int>
     */
    private function getAlreadyNotifiedFollowerIds(\Illuminate\Database\Eloquent\Collection $followers, Post $post): Collection
    {
        if ($followers->isEmpty()) {
            return collect();
        }

        return DB::table('notifications')
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

        $author = $post->user;

        if ($author === null) {
            return;
        }

        $sentCount = 0;
        $skippedCount = 0;

        User::query()
            ->whereIn('id', function ($query) use ($author) {
                $query->select('follower_id')
                    ->from('follows')
                    ->where('author_id', $author->id);
            })
            ->where('id', '!=', $author->id)
            ->where('notify_followed_author_posts', true)
            ->chunk(500, function ($followers) use ($post, $author, &$sentCount, &$skippedCount): void {
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
                        $follower->notify(new FollowedAuthorPostedNotification($post));
                        $sentCount++;
                    } catch (Throwable $exception) {
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
    }
}
