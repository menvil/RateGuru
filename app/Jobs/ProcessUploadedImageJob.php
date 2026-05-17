<?php

namespace App\Jobs;

use App\Models\Post;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessUploadedImageJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $postId,
    ) {}

    public function handle(): void
    {
        $post = Post::query()->find($this->postId);

        if (! $post) {
            return;
        }

        // Placeholder. Real image processing later.
    }
}
