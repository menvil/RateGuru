<?php

namespace App\Jobs;

use App\Actions\Follows\NotifyFollowersAboutNewPostAction;
use App\Models\Post;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

final class NotifyFollowersAboutNewPostJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [60, 300, 600];

    public int $timeout = 120;

    public function __construct(public readonly int $postId) {}

    public function handle(NotifyFollowersAboutNewPostAction $action): void
    {
        $post = Post::query()->with('user')->find($this->postId);

        if ($post === null) {
            Log::warning('NotifyFollowersAboutNewPostJob: post not found, skipping.', ['post_id' => $this->postId]);

            return;
        }

        $action->handle($post);
    }
}
