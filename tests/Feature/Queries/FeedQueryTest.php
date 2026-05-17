<?php

use App\Models\Post;
use App\Queries\Feed\FeedQuery;

it('returns only published posts', function () {
    $published = Post::factory()->published()->create();
    Post::factory()->pending()->create();
    Post::factory()->hidden()->create();
    Post::factory()->rejected()->create();

    $posts = app(FeedQuery::class)->get();

    expect($posts->pluck('id')->all())->toBe([$published->id]);
});

it('sorts published posts by newest', function () {
    $old = Post::factory()->published()->create([
        'published_at' => now()->subDay(),
    ]);

    $new = Post::factory()->published()->create([
        'published_at' => now(),
    ]);

    $posts = app(FeedQuery::class)->get(sort: 'newest');

    expect($posts->pluck('id')->all())->toBe([$new->id, $old->id]);
});

it('sorts published posts by top score', function () {
    // $high is created first (older) so newest sort would put $low first — top sort must override this
    $high = Post::factory()->published()->create([
        'upvotes_count' => 10,
        'downvotes_count' => 2,
        'published_at' => now()->subDay(),
    ]);

    $low = Post::factory()->published()->create([
        'upvotes_count' => 3,
        'downvotes_count' => 1,
        'published_at' => now(),
    ]);

    $posts = app(FeedQuery::class)->get(sort: 'top');

    expect($posts->pluck('id')->all())->toBe([$high->id, $low->id]);
});
