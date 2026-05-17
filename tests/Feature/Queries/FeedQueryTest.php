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
