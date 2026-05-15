<?php

use App\Models\Post;

it('only returns published posts', function () {
    $published = Post::factory()->published()->create();
    Post::factory()->pending()->create();
    Post::factory()->hidden()->create();
    Post::factory()->rejected()->create();

    expect(Post::published()->pluck('id')->all())->toBe([$published->id]);
});
