<?php

use App\Models\Post;
use App\Models\RatingGroup;
use App\Queries\Feed\FeedQuery;

beforeEach(function () {
    seedFeedFilterGroups();

    $this->sourceGroup = RatingGroup::query()->where('key', 'source')->firstOrFail();
});

it('filters posts by author-chosen category option key', function () {
    $homemade = $this->sourceGroup->options()->where('key', 'homemade')->firstOrFail();
    $restaurant = $this->sourceGroup->options()->where('key', 'restaurant')->firstOrFail();

    $homemadePost = Post::factory()->published()->create(['category_option_id' => $homemade->id]);
    $restaurantPost = Post::factory()->published()->create(['category_option_id' => $restaurant->id]);
    $uncategorisedPost = Post::factory()->published()->create();

    $results = app(FeedQuery::class)->get(category: ['homemade']);

    expect($results->pluck('id')->all())->toContain($homemadePost->id);
    expect($results->pluck('id')->all())->not->toContain($restaurantPost->id);
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
