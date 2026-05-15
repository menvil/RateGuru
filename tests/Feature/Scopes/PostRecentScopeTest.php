<?php

use App\Models\Post;

it('orders posts by most recent first', function () {
    $old = Post::factory()->create(['created_at' => now()->subDay()]);
    $new = Post::factory()->create(['created_at' => now()]);

    $results = Post::recent()->get();

    expect($results->first()->id)->toBe($new->id);
    expect($results->last()->id)->toBe($old->id);
});
