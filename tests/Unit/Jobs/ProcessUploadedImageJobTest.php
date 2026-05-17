<?php

use App\Jobs\ProcessUploadedImageJob;
use App\Models\Post;
use Illuminate\Contracts\Queue\ShouldQueue;

it('has process uploaded image job', function () {
    $job = new ProcessUploadedImageJob(postId: 1);

    expect($job)->toBeInstanceOf(ProcessUploadedImageJob::class);
});

it('implements should queue', function () {
    $job = new ProcessUploadedImageJob(postId: 1);

    expect($job)->toBeInstanceOf(ShouldQueue::class);
});

it('stores post id', function () {
    $job = new ProcessUploadedImageJob(postId: 42);

    expect($job->postId)->toBe(42);
});
