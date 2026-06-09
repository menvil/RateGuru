<?php

use App\Models\Post;

it('renders post show mobile-safe structure', function () {
    $post = Post::factory()->published()->create();

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('data-testid="post-show-page"', false);
});

it('renders post show with responsive single-column layout', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Mobile Post Show Test',
    ]);

    $response = $this->get(route('posts.show', $post));

    $response->assertOk()
        ->assertSee('data-testid="post-show-meta"', false)
        ->assertSee('data-testid="post-show-hero"', false);
});

it('renders post show title with break-words for long titles', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Averylongtitlewithnospacesthatcouldcausehorizontaloverflowonmobile',
    ]);

    $response = $this->get(route('posts.show', $post));

    $response->assertOk();
    $this->assertStringContainsString('break-words', $response->getContent());
});
