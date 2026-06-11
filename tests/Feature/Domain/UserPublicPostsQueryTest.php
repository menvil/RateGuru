<?php

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;
use App\Queries\UserPublicPostsQuery;

it('returns only public posts for profile user', function () {
    $user = User::factory()->create();

    $public = Post::factory()->for($user)->published()->create([
        'title' => 'Public Post',
    ]);

    Post::factory()->for($user)->create([
        'title' => 'Hidden Post',
        'status' => PostStatus::Hidden,
    ]);

    $posts = app(UserPublicPostsQuery::class)->forProfile($user);

    expect($posts->pluck('id'))->toContain($public->id);
    expect($posts->pluck('title'))->not->toContain('Hidden Post');
});

it('does not return pending or rejected posts', function () {
    $user = User::factory()->create();

    Post::factory()->for($user)->published()->create(['title' => 'Published']);
    Post::factory()->for($user)->pending()->create(['title' => 'Pending']);
    Post::factory()->for($user)->create(['status' => PostStatus::Rejected, 'title' => 'Rejected']);

    $posts = app(UserPublicPostsQuery::class)->forProfile($user);

    expect($posts->pluck('title'))->toContain('Published');
    expect($posts->pluck('title'))->not->toContain('Pending');
    expect($posts->pluck('title'))->not->toContain('Rejected');
});

it('only returns posts for the given user', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    Post::factory()->for($user)->published()->create(['title' => 'My Post']);
    Post::factory()->for($other)->published()->create(['title' => 'Other Post']);

    $posts = app(UserPublicPostsQuery::class)->forProfile($user);

    expect($posts->pluck('title'))->toContain('My Post');
    expect($posts->pluck('title'))->not->toContain('Other Post');
});

it('returns paginated results', function () {
    $user = User::factory()->create();

    Post::factory()->count(20)->for($user)->published()->create();

    $posts = app(UserPublicPostsQuery::class)->forProfile($user, perPage: 10);

    expect($posts->total())->toBe(20);
    expect($posts->count())->toBe(10);
});

it('eager loads user and tags', function () {
    $user = User::factory()->create();
    Post::factory()->count(3)->for($user)->published()->create();

    $posts = app(UserPublicPostsQuery::class)->forProfile($user);

    expect($posts->first()->relationLoaded('user'))->toBeTrue();
    expect($posts->first()->relationLoaded('tags'))->toBeTrue();
});
