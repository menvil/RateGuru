<?php

use App\Jobs\ProcessUploadedImageJob;
use App\Models\Post;
use Illuminate\Support\Facades\Bus;

it('can dispatch process uploaded image job', function () {
    Bus::fake();

    $post = Post::factory()->create();

    ProcessUploadedImageJob::dispatch($post->id);

    Bus::assertDispatched(ProcessUploadedImageJob::class, function ($job) use ($post) {
        return $job->postId === $post->id;
    });
});
