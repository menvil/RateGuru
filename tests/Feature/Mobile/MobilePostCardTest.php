<?php

use App\Models\Post;

it('renders post card with mobile-safe structure', function () {
    $post = Post::factory()->published()->create([
        'title' => str_repeat('Long mobile title ', 5),
    ]);

    $this->get(route('feed'))
        ->assertOk()
        ->assertSee('data-testid="post-card"', false);
});

it('renders post card title with break-words for long titles', function () {
    $post = Post::factory()->published()->create([
        'title' => str_repeat('Verylongtitlewithnospacesthatcouldoverflow', 3),
    ]);

    $response = $this->get(route('feed'));

    $response->assertOk()
        ->assertSee('data-testid="post-card-title"', false);

    $this->assertStringContainsString('break-words', $response->getContent());
});

it('renders post card with overflow-hidden container', function () {
    Post::factory()->published()->create();

    $response = $this->get(route('feed'));

    $response->assertOk()
        ->assertSee('overflow-hidden', false);
});
