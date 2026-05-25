<?php

use App\Enums\PostStatus;
use App\Models\Post;
use App\Queries\Feed\FeedQuery;
use Database\Seeders\DemoDatabaseSeeder;
use Database\Seeders\DemoPublishedPostsSeeder;
use Database\Seeders\DemoTagsSeeder;
use Database\Seeders\DemoUsersSeeder;

it('seeds published demo posts', function () {
    $this->seed(DemoUsersSeeder::class);
    $this->seed(DemoTagsSeeder::class);
    $this->seed(DemoPublishedPostsSeeder::class);

    expect(Post::query()->where('status', PostStatus::Published)->count())
        ->toBe(6);
});

it('seeds published posts with authors and tags', function () {
    $this->seed(DemoDatabaseSeeder::class);

    $post = Post::query()->where('status', PostStatus::Published)->firstOrFail();

    expect($post->user)->not->toBeNull();
    expect($post->tags()->count())->toBeGreaterThan(0);
});

it('seeded published posts are visible through feed query', function () {
    $this->seed(DemoDatabaseSeeder::class);

    $posts = app(FeedQuery::class)->get();

    expect($posts)->not->toBeEmpty();
    expect($posts->every(fn (Post $post) => $post->status === PostStatus::Published))->toBeTrue();
});
