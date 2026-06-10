<?php

use App\Models\Post;
use App\Models\PostSave;
use App\Models\User;
use App\Support\SavedPosts\SavedPostState;
use Illuminate\Support\Facades\DB;

it('does not perform n plus one queries for saved state on feed posts', function () {
    $user = User::factory()->create();
    $posts = Post::factory()->count(10)->published()->create();

    PostSave::factory()->create([
        'user_id' => $user->id,
        'post_id' => $posts[0]->id,
    ]);

    $queryCount = 0;

    DB::listen(function () use (&$queryCount): void {
        $queryCount++;
    });

    app(SavedPostState::class)->forUserAndPosts($user, $posts);

    expect($queryCount)->toBeLessThanOrEqual(2);
});

it('performs zero queries when user is null', function () {
    $posts = Post::factory()->count(5)->published()->create();

    $queryCount = 0;

    DB::listen(function () use (&$queryCount): void {
        $queryCount++;
    });

    app(SavedPostState::class)->forUserAndPosts(null, $posts);

    expect($queryCount)->toBe(0);
});
