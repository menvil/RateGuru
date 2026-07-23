<?php

use App\Models\Category;
use App\Models\Post;
use App\Queries\Feed\FeedQuery;

it('filters posts by standalone category slug', function () {
    $firstCategory = Category::factory()->create(['slug' => 'desserts']);
    $secondCategory = Category::factory()->create(['slug' => 'soups']);

    $firstCategoryPost = Post::factory()->published()->create(['category_id' => $firstCategory->id]);
    $secondCategoryPost = Post::factory()->published()->create(['category_id' => $secondCategory->id]);
    $uncategorisedPost = Post::factory()->published()->create();

    $results = app(FeedQuery::class)->get(category: ['desserts']);

    expect($results->pluck('id')->all())->toContain($firstCategoryPost->id);
    expect($results->pluck('id')->all())->not->toContain($secondCategoryPost->id);
    expect($results->pluck('id')->all())->not->toContain($uncategorisedPost->id);
});

it('matches any selected category while each post keeps only one category', function () {
    $desserts = Category::factory()->create(['slug' => 'desserts']);
    $soups = Category::factory()->create(['slug' => 'soups']);

    $dessertPost = Post::factory()->published()->create(['category_id' => $desserts->id]);
    $soupPost = Post::factory()->published()->create(['category_id' => $soups->id]);
    $otherPost = Post::factory()->published()->create();

    $results = app(FeedQuery::class)->get(category: ['desserts', 'soups']);

    expect($results->pluck('id')->all())->toContain($dessertPost->id, $soupPost->id)
        ->not->toContain($otherPost->id);
});

it('returns everything when the category filter is empty', function () {
    $post = Post::factory()->published()->create();

    $results = app(FeedQuery::class)->get(category: []);

    expect($results->pluck('id')->all())->toContain($post->id);
});

it('does not match posts through an inactive category slug', function () {
    $category = Category::factory()->inactive()->create(['slug' => 'archived']);
    $post = Post::factory()->published()->create(['category_id' => $category->id]);

    $results = app(FeedQuery::class)->get(category: ['archived']);

    expect($results->pluck('id')->all())->not->toContain($post->id);
});
