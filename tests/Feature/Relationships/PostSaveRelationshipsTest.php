<?php

use App\Models\Post;
use App\Models\PostSave;
use App\Models\User;

it('user has saved posts relationship', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    PostSave::factory()->create([
        'user_id' => $user->id,
        'post_id' => $post->id,
    ]);

    expect($user->postSaves)->toHaveCount(1);
    expect($user->savedPostItems->first()->is($post))->toBeTrue();
});

it('post has saved by users relationship', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    PostSave::factory()->create([
        'user_id' => $user->id,
        'post_id' => $post->id,
    ]);

    expect($post->savedByUsers->first()->is($user))->toBeTrue();
});

it('user saved post items returns only posts saved by that user', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $post = Post::factory()->published()->create();

    PostSave::factory()->create(['user_id' => $user->id, 'post_id' => $post->id]);
    PostSave::factory()->create(['user_id' => $other->id, 'post_id' => $post->id]);

    expect($user->savedPostItems)->toHaveCount(1);
    expect($other->savedPostItems)->toHaveCount(1);
});
