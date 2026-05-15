<?php

use App\Models\Post;

it('filters hidden posts', function () {
    $hidden = Post::factory()->hidden()->create();
    Post::factory()->published()->create();
    Post::factory()->pending()->create();

    $results = Post::hidden()->get();

    expect($results->pluck('id'))->toContain($hidden->id);
    expect($results)->toHaveCount(1);
});
