<?php

namespace App\Jobs;

use App\Actions\Follows\NotifyFollowersAboutNewPostAction;
use App\Models\Post;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class NotifyFollowersAboutNewPostJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $postId) {}

    public function handle(NotifyFollowersAboutNewPostAction $action): void
    {
        $post = Post::query()->with('user')->find($this->postId);

        if ($post === null) {
            return;
        }

        $action->handle($post);
    }
}
