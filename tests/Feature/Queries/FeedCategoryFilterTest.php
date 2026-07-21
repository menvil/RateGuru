<?php

use App\Models\Post;
use App\Models\RatingGroup;
use App\Queries\Feed\FeedQuery;

beforeEach(function () {
    seedFeedFilterGroups();

    $this->sourceGroup = RatingGroup::query()->where('key', 'source')->firstOrFail();
});

it('filters posts by author-chosen category option key', function () {
    $firstCategory = $this->sourceGroup->options()->where('key', 'source_a')->firstOrFail();
    $secondCategory = $this->sourceGroup->options()->where('key', 'source_b')->firstOrFail();

    $firstCategoryPost = Post::factory()->published()->create(['category_option_id' => $firstCategory->id]);
    $secondCategoryPost = Post::factory()->published()->create(['category_option_id' => $secondCategory->id]);
    $uncategorisedPost = Post::factory()->published()->create();

    $results = app(FeedQuery::class)->get(category: ['source_a']);

    expect($results->pluck('id')->all())->toContain($firstCategoryPost->id);
    expect($results->pluck('id')->all())->not->toContain($secondCategoryPost->id);
    expect($results->pluck('id')->all())->not->toContain($uncategorisedPost->id);
});

it('filters by any configured category option key', function () {
    $custom = $this->sourceGroup->options()->create([
        'key' => 'natural',
        'label' => 'Natural',
        'is_active' => true,
        'sort_order' => 30,
    ]);

    $naturalPost = Post::factory()->published()->create(['category_option_id' => $custom->id]);
    $otherPost = Post::factory()->published()->create();

    $results = app(FeedQuery::class)->get(category: ['natural']);

    expect($results->pluck('id')->all())->toContain($naturalPost->id);
    expect($results->pluck('id')->all())->not->toContain($otherPost->id);
});

it('returns everything when the category filter is empty', function () {
    $post = Post::factory()->published()->create();

    $results = app(FeedQuery::class)->get(category: []);

    expect($results->pluck('id')->all())->toContain($post->id);
});
