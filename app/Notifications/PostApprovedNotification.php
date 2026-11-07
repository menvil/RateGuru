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
     * Stored as a translation key plus its parameters, never as a rendered
     * sentence. A notification is written once and read for as long as it
     * lives in the bell, potentially in a language the reader had not chosen
     * yet when it was created — so the language has to be decided at read
     * time, and a stored sentence can never be.
     *
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
            'message_key' => 'ui.notifications.messages.post_approved',
            'message_params' => [],
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
