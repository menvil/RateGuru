<?php

use App\Models\Post;
use App\Models\PostSave;
use App\Models\User;
use App\Support\SavedPosts\SavedPostState;

it('returns saved states for multiple posts', function () {
    $user = User::factory()->create();
    $posts = Post::factory()->count(3)->published()->create();

    PostSave::factory()->create([
        'user_id' => $user->id,
        'post_id' => $posts[0]->id,
    ]);

    $stateMap = app(SavedPostState::class)->forUserAndPosts($user, $posts);

    expect($stateMap->isSaved($posts[0]))->toBeTrue();
    expect($stateMap->isSaved($posts[1]))->toBeFalse();
    expect($stateMap->isSaved($posts[2]))->toBeFalse();
});

it('returns false for all posts when user is null', function () {
    $posts = Post::factory()->count(2)->published()->create();

    $stateMap = app(SavedPostState::class)->forUserAndPosts(null, $posts);

    expect($stateMap->isSaved($posts[0]))->toBeFalse();
    expect($stateMap->isSaved($posts[1]))->toBeFalse();
});

it('checks single post saved state for user', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    PostSave::factory()->create([
        'user_id' => $user->id,
        'post_id' => $post->id,
    ]);

    expect(app(SavedPostState::class)->forUserAndPost($user, $post))->toBeTrue();
});

it('returns false for single post when not saved', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    expect(app(SavedPostState::class)->forUserAndPost($user, $post))->toBeFalse();
});
