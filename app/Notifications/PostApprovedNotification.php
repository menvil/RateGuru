<?php

namespace App\Notifications;

use App\Models\Post;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

final class PostApprovedNotification extends Notification
{
    public function __construct(
        public readonly Post $post,
        public readonly User $actor,
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
            'type' => 'post_approved',
            'post_id' => $this->post->id,
            'post_title' => $this->post->title,
            'actor_id' => $this->actor->id,
            'actor_name' => $this->actor->name,
            'actor_username' => $this->actor->username,
            'message' => 'Your post was approved',
            'url' => $this->postUrl(),
        ];
    }

    private function postUrl(): string
    {
        return Route::has('posts.show')
            ? route('posts.show', $this->post)
            : '#';
    }
}
