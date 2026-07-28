<?php

namespace App\Jobs;

use App\Models\Post;
use App\Services\Images\OpenGraphImageGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

final class ProcessUploadedImageJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $postId,
    ) {}

    public function handle(OpenGraphImageGenerator $generator): void
    {
        $post = Post::query()->find($this->postId);

        if (! $post || trim((string) $post->image_path) === '') {
            return;
        }

        try {
            $path = $generator->generate($post);
        } catch (RuntimeException $exception) {
            report($exception);

            return;
        }

        if ($path === null) {
            return;
        }

        $post->forceFill(['og_image_path' => $path])->save();
    }
}
