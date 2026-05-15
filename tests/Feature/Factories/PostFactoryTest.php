<?php

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;

it('can create a post with factory', function () {
    $post = Post::factory()->create();

    expect($post)->toBeInstanceOf(Post::class);
    expect($post->exists)->toBeTrue();
    expect($post->user)->toBeInstanceOf(User::class);
    expect($post->status)->toBe(PostStatus::Pending);
});

it('can create a published post', function () {
    $post = Post::factory()->published()->create();

    expect($post->status)->toBe(PostStatus::Published);
    expect($post->published_at)->not->toBeNull();
});
