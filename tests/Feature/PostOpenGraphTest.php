<?php

use App\Models\Post;

it('renders open graph image for post show page', function () {
    config(['app.url' => 'https://rateguru.test']);

    $post = Post::factory()->published()->create([
        'image_url' => null,
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('<meta property="og:image"', false)
        ->assertSee('https://rateguru.test/images/og/rateguru-post-placeholder.svg', false);
});

it('uses post image as open graph image when available', function () {
    config(['app.url' => 'https://rateguru.test']);

    $post = Post::factory()->published()->create([
        'image_url' => '/storage/posts/demo.jpg',
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('<meta property="og:image"', false)
        ->assertSee('https://rateguru.test/storage/posts/demo.jpg', false);
});
