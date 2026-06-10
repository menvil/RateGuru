<?php

namespace App\Notifications;

use App\Models\Post;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

final class FollowedAuthorPostedNotification extends Notification
{
    public function __construct(public readonly Post $post) {}

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
        $author = $this->post->user;

        return [
            'type' => 'followed_author_posted',
            'post_id' => $this->post->id,
            'post_title' => $this->post->title,
            'author_id' => $author?->id,
            'author_name' => $author?->name ?? $author?->username,
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
