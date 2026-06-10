<?php

namespace App\Actions\Follows;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;
use App\Notifications\FollowedAuthorPostedNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class NotifyFollowersAboutNewPostAction
{
    /**
     * @param  Collection<int, User>  $followers
     * @return Collection<int, int>
     */
    private function getAlreadyNotifiedFollowerIds(Collection $followers, Post $post): Collection
    {
        if ($followers->isEmpty()) {
            return collect();
        }

        $followerIds = $followers->pluck('id')->all();
        $notificationType = FollowedAuthorPostedNotification::class;
        $postId = $post->id;

        return DB::table('notifications')
            ->where('type', $notificationType)
            ->whereIn('notifiable_id', $followerIds)
            ->get()
            ->filter(fn (object $row) => (json_decode($row->data, true)['post_id'] ?? null) === $postId)
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

        $followers = User::query()
            ->whereIn('id', function ($query) use ($author) {
                $query->select('follower_id')
                    ->from('follows')
                    ->where('author_id', $author->id);
            })
            ->where('id', '!=', $author->id)
            ->where('notify_followed_author_posts', true)
            ->get();

        $alreadyNotifiedIds = $this->getAlreadyNotifiedFollowerIds($followers, $post);

        foreach ($followers as $follower) {
            if ($alreadyNotifiedIds->contains($follower->id)) {
                continue;
            }

            try {
                $follower->notify(new FollowedAuthorPostedNotification($post));
            } catch (Throwable $exception) {
                report($exception);

                Log::error('Failed to send followed author posted notification.', [
                    'post_id' => $post->id,
                    'follower_id' => $follower->id,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }
    }
}
