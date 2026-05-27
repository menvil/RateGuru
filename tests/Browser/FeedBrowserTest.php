<?php

use App\Models\Post;

it('loads the feed page and shows published posts', function () {
    Post::factory()->published()->create([
        'title' => 'Browser Smoke Feed Post',
    ]);

    visit(route('feed'))
        ->assertSee('Browser Smoke Feed Post')
        ->assertPresent('[data-testid="feed-page"]')
        ->assertPresent('[data-testid="post-card"]');
});
