<?php

use App\Models\Post;

it('has posts show route', function () {
    $post = Post::factory()->published()->create();

    $this->get(route('posts.show', $post))
        ->assertOk();
});
