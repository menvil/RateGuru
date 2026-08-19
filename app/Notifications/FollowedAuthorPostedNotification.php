<?php

namespace App\Notifications;

use App\Models\Post;
use App\Models\User;
use Illuminate\Notifications\Notification;

final class FollowedAuthorPostedNotification extends Notification
{
    /**
     * $author must be the FRESH locked author provided by
     * LifecycleSafeDatabaseNotifier — never resolved lazily from the post
     * relation, which could carry a stale pre-anonymization snapshot.
     */
    public function __construct(
        public readonly Post $post,
        public readonly User $author,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'followed_author_posted',
            'post_id' => $this->post->id,
            'post_title' => $this->post->title,
            'author_id' => $this->author->id,
            'author_name' => $this->author->name,
            'author_username' => $this->author->username,
            'message' => '@'.$this->author->username.' posted '.$this->post->title,
            'url' => $this->postUrl(),
        ];
    }

    private function postUrl(): string
    {
        return route('posts.show', $this->post);
    }
}
