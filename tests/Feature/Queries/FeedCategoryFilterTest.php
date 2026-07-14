<?php

use App\Enums\OriginType;
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

    $results = app(FeedQuery::class)->get(origin: ['homemade']);

    expect($results->pluck('id')->all())->toContain($homemadePost->id);
    expect($results->pluck('id')->all())->not->toContain($restaurantPost->id);
    expect($results->pluck('id')->all())->not->toContain($uncategorisedPost->id);
});

it('matches posts by legacy origin_truth alongside category option keys', function () {
    $homemade = $this->sourceGroup->options()->where('key', 'homemade')->firstOrFail();

    $legacyPost = Post::factory()->published()->create(['origin_truth' => OriginType::Homemade]);
    $categorisedPost = Post::factory()->published()->create(['category_option_id' => $homemade->id]);

    $results = app(FeedQuery::class)->get(origin: ['homemade']);

    expect($results->pluck('id')->all())->toContain($legacyPost->id);
    expect($results->pluck('id')->all())->toContain($categorisedPost->id);
});

it('filters by an option key that is not a legacy enum value', function () {
    $custom = $this->sourceGroup->options()->create([
        'key' => 'natural',
        'label' => 'Natural',
        'is_active' => true,
        'sort_order' => 30,
    ]);

    $naturalPost = Post::factory()->published()->create(['category_option_id' => $custom->id]);
    $otherPost = Post::factory()->published()->create();

    $results = app(FeedQuery::class)->get(origin: ['natural']);

    expect($results->pluck('id')->all())->toContain($naturalPost->id);
    expect($results->pluck('id')->all())->not->toContain($otherPost->id);
});

it('returns everything when the origin filter is empty', function () {
    $post = Post::factory()->published()->create();

    $results = app(FeedQuery::class)->get(origin: []);

    expect($results->pluck('id')->all())->toContain($post->id);
});
