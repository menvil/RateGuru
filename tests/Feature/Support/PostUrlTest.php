<?php

use App\Models\Post;
use App\Support\Urls\PostUrl;

it('returns absolute canonical post url', function () {
    config(['app.url' => 'https://rateguru.test']);

    $post = Post::factory()->published()->create();

    $url = app(PostUrl::class)->canonical($post);

    expect($url)
        ->toStartWith('https://rateguru.test')
        ->toBe('https://rateguru.test'.route('posts.show', $post, absolute: false));
});

it('canonical_post_url helper delegates to post url service', function () {
    config(['app.url' => 'https://rateguru.test']);

    $post = Post::factory()->published()->create();

    expect(canonical_post_url($post))
        ->toBe(app(PostUrl::class)->canonical($post));
});
