<?php

namespace App\Notifications;

use App\Enums\PostStatus;
use App\Models\Post;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

/**
 * Tells the author that the video or GIF of their post has been processed:
 * the post is now published, waits for a moderator, or — when the file
 * could not be processed — stays a draft they may delete and upload again.
 */
final class VideoPostProcessedNotification extends Notification
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
     * A translation key and its parameters, never a rendered sentence: the
     * reader's language is decided when the bell is read (see
     * PostApprovedNotification).
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $common = [
            'post_id' => $this->post->id,
            'post_title' => $this->post->title,
            'message_params' => [],
            // Only a published post has a page anyone may open.
            'url' => $this->post->status === PostStatus::Published && Route::has('posts.show')
                ? route('posts.show', $this->post)
                : '#',
        ];

        // Each key written out whole, so the translation check finds it.
        return match ($this->post->status) {
            PostStatus::Published => ['type' => 'video_post_published', 'message_key' => 'ui.notifications.messages.video_post_published'] + $common,
            PostStatus::Pending => ['type' => 'video_post_pending', 'message_key' => 'ui.notifications.messages.video_post_pending'] + $common,
            default => ['type' => 'video_post_failed', 'message_key' => 'ui.notifications.messages.video_post_failed'] + $common,
        };
    }
}
