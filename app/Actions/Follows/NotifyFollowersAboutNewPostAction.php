<?php

namespace App\Actions\Follows;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;
use App\Notifications\FollowedAuthorPostedNotification;
use Illuminate\Support\Facades\Log;
use Throwable;

final class NotifyFollowersAboutNewPostAction
{
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

        $notification = new FollowedAuthorPostedNotification($post);

        foreach ($followers as $follower) {
            try {
                $follower->notify($notification);
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
