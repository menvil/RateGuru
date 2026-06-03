<?php

use App\Models\Post;
use App\Queries\Feed\FeedQuery;

it('uses default feed per page when perPage is missing', function () {
    config()->set('feed.default_per_page', 12);
    config()->set('feed.max_per_page', 50);

    Post::factory()->count(20)->published()->create();

    $posts = app(FeedQuery::class)->paginate(perPage: null);

    expect($posts->perPage())->toBe(12);
});

it('clamps requested feed per page to configured max', function () {
    config()->set('feed.default_per_page', 12);
    config()->set('feed.max_per_page', 50);

    Post::factory()->count(100)->published()->create();

    $posts = app(FeedQuery::class)->paginate(perPage: 1000);

    expect($posts->perPage())->toBe(50);
});

it('falls back to default feed per page for invalid perPage', function () {
    config()->set('feed.default_per_page', 12);
    config()->set('feed.max_per_page', 50);

    Post::factory()->count(20)->published()->create();

    $posts = app(FeedQuery::class)->paginate(perPage: -10);

    expect($posts->perPage())->toBe(12);
});

it('clamps invalid configured default feed per page to configured max', function () {
    config()->set('feed.default_per_page', 100);
    config()->set('feed.max_per_page', 50);

    Post::factory()->count(100)->published()->create();

    $posts = app(FeedQuery::class)->paginate(perPage: null);

    expect($posts->perPage())->toBe(50);
});

it('keeps normalized feed per page above zero when config is invalid', function () {
    config()->set('feed.default_per_page', 0);
    config()->set('feed.max_per_page', 0);

    Post::factory()->count(5)->published()->create();

    $posts = app(FeedQuery::class)->paginate(perPage: null);

    expect($posts->perPage())->toBe(1);
});
