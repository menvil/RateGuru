<?php

use App\Models\Post;

it('orders posts by hot score descending', function () {
    $cold = Post::factory()->create(['hot_score' => 1]);
    $hot = Post::factory()->create(['hot_score' => 10]);

    $results = Post::hot()->get();

    expect($results->first()->id)->toBe($hot->id);
    expect($results->last()->id)->toBe($cold->id);
});
