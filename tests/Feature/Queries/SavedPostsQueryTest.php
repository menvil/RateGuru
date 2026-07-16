<?php

use App\Models\Post;
use App\Models\PostSave;
use App\Models\User;
use App\Queries\SavedPosts\SavedPostsQuery;

it('returns saved posts for user ordered by saved date desc', function () {
    $user = User::factory()->create();
    $first = Post::factory()->published()->create();
    $second = Post::factory()->published()->create();

    PostSave::factory()->create([
        'user_id' => $user->id,
        'post_id' => $first->id,
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ]);

    PostSave::factory()->create([
        'user_id' => $user->id,
        'post_id' => $second->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $result = app(SavedPostsQuery::class)->forUser($user);

    expect($result->items()[0]->id)->toBe($second->id);
    expect($result->items()[1]->id)->toBe($first->id);
});

it('uses post id as the final deterministic saved posts order', function () {
    $user = User::factory()->create();
    $posts = Post::factory()->published()->count(3)->create();
    $savedAt = now()->startOfSecond();

    foreach ($posts as $post) {
        PostSave::factory()->create([
            'user_id' => $user->id,
            'post_id' => $post->id,
            'created_at' => $savedAt,
            'updated_at' => $savedAt,
        ]);
    }

    $result = app(SavedPostsQuery::class)->forUser($user);

    expect($result->pluck('id')->all())->toBe($posts->pluck('id')->reverse()->values()->all());
});

it('does not return saved posts from other users', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $post = Post::factory()->published()->create([
        'title' => 'Private Saved Post',
    ]);

    PostSave::factory()->create([
        'user_id' => $owner->id,
        'post_id' => $post->id,
    ]);

    $result = app(SavedPostsQuery::class)->forUser($other);

    expect($result->total())->toBe(0);
});

it('does not return unpublished posts in saved list', function () {
    $user = User::factory()->create();
    $published = Post::factory()->published()->create();
    $pending = Post::factory()->pending()->create();

    PostSave::factory()->create(['user_id' => $user->id, 'post_id' => $published->id]);
    PostSave::factory()->create(['user_id' => $user->id, 'post_id' => $pending->id]);

    $result = app(SavedPostsQuery::class)->forUser($user);

    expect($result->total())->toBe(1);
    expect($result->items()[0]->id)->toBe($published->id);
});

it('returns empty paginator when user has no saved posts', function () {
    $user = User::factory()->create();

    $result = app(SavedPostsQuery::class)->forUser($user);

    expect($result->total())->toBe(0);
    expect($result->isEmpty())->toBeTrue();
});
