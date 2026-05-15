<?php

use App\Models\Post;

it('only returns pending posts', function () {
    $pending = Post::factory()->pending()->create();
    Post::factory()->published()->create();
    Post::factory()->hidden()->create();
    Post::factory()->rejected()->create();

    expect(Post::pending()->pluck('id')->all())->toBe([$pending->id]);
});
