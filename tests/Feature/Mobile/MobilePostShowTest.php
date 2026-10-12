<?php

use App\Models\Post;

it('renders post show in a single mobile-safe column that wraps long titles', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Averylongtitlewithnospacesthatcouldcausehorizontaloverflowonmobile',
    ]);

    $response = $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('data-testid="post-show-page"', false)
        ->assertSee('data-testid="post-show-meta"', false)
        ->assertSee('data-testid="post-show-hero"', false);

    $this->assertStringContainsString('break-words', $response->getContent());
});
