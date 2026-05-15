<?php

use App\Models\Post;

it('filters published posts', function () {
    $published = Post::factory()->published()->create();
    Post::factory()->pending()->create();
    Post::factory()->hidden()->create();

    $results = Post::published()->get();

    expect($results->pluck('id'))->toContain($published->id);
    expect($results)->toHaveCount(1);
});
