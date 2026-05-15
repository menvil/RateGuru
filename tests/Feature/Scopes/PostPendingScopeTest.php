<?php

use App\Models\Post;

it('filters pending posts', function () {
    $pending = Post::factory()->pending()->create();
    Post::factory()->published()->create();
    Post::factory()->hidden()->create();

    $results = Post::pending()->get();

    expect($results->pluck('id'))->toContain($pending->id);
    expect($results)->toHaveCount(1);
});
