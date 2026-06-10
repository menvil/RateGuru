<?php

use App\Models\Post;
use App\Models\PostSave;
use App\Models\User;

it('creates post save model via factory', function () {
    $postSave = PostSave::factory()->create();

    expect($postSave)->toBeInstanceOf(PostSave::class);
    expect($postSave->exists)->toBeTrue();
    expect($postSave->user)->toBeInstanceOf(User::class);
    expect($postSave->post)->toBeInstanceOf(Post::class);
});

it('creates post save for a specific user and post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    $postSave = PostSave::factory()->create([
        'user_id' => $user->id,
        'post_id' => $post->id,
    ]);

    expect($postSave->user->id)->toBe($user->id);
    expect($postSave->post->id)->toBe($post->id);
});
