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

it('renders open graph title for post show page', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Best Pasta in Sofia',
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('<meta property="og:title" content="Best Pasta in Sofia · RateGuru">', false);
});

it('escapes open graph title content', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Pasta "Special" <script>',
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertDontSee('<script>', false)
        ->assertSee('Pasta &quot;Special&quot; &lt;script&gt; · RateGuru', false);
});

it('renders open graph description for post show page', function () {
    $post = Post::factory()->published()->create([
        'description' => 'A detailed review of a handmade pasta dish in Sofia.',
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('<meta property="og:description" content="A detailed review of a handmade pasta dish in Sofia.">', false);
});

it('renders fallback open graph description when post has no description', function () {
    $post = Post::factory()->published()->create([
        'description' => null,
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('See and rate this post on RateGuru.', false);
});

it('strips html and truncates open graph description', function () {
    $post = Post::factory()->published()->create([
        'description' => '<b>'.str_repeat('Long text ', 40).'</b>',
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('<meta property="og:description" content="'.trim(str_repeat('Long text ', 16)).'">', false)
        ->assertDontSee('<b>', false);
});
