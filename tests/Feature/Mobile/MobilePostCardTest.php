<?php

use App\Models\Post;

it('renders post card title with break-words for long titles', function () {
    $post = Post::factory()->published()->create([
        'title' => str_repeat('Verylongtitlewithnospacesthatcouldoverflow', 3),
    ]);

    $response = $this->get(route('feed'));

    $response->assertOk()
        ->assertSee('data-testid="post-card-title"', false);

    $this->assertStringContainsString('break-words', $response->getContent());
});
