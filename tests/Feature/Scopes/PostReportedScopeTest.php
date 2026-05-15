<?php

use App\Models\Post;

it('filters reported posts', function () {
    $reported = Post::factory()->create(['reports_count' => 1]);
    Post::factory()->create(['reports_count' => 0]);

    $results = Post::reported()->get();

    expect($results->pluck('id'))->toContain($reported->id);
    expect($results)->toHaveCount(1);
});
