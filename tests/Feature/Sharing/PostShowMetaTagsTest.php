<?php

use App\Models\Post;

it('renders opengraph meta tags on post show', function () {
    $post = Post::factory()->published()->create([
        'title' => 'OG Test Post',
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('property="og:title"', false)
        ->assertSee('property="og:url"', false)
        ->assertSee('name="twitter:card"', false);
});

it('renders canonical link tag on post show', function () {
    config(['app.url' => 'https://rateguru.test']);

    $post = Post::factory()->published()->create();

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('rel="canonical"', false)
        ->assertSee(canonical_post_url($post), false);
});

it('uses summary twitter card when post has no image', function () {
    $post = Post::factory()->published()->create([
        'image_path' => null,
        'image_url' => null,
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('content="summary"', false);
});

it('uses summary_large_image twitter card when post has image', function () {
    $post = Post::factory()->published()->create([
        'image_url' => 'https://cdn.example.com/image.jpg',
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('content="summary_large_image"', false);
});
