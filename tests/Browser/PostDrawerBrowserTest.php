<?php

use App\Models\Post;

it('opens post drawer from feed post card', function () {
    Post::factory()->published()->create([
        'title' => 'Browser Drawer Test Post',
    ]);

    visit(route('feed'))
        ->assertSee('Browser Drawer Test Post')
        ->click('[data-testid="post-card"]')
        ->waitForText('Browser Drawer Test Post')
        ->assertVisible('[data-testid="post-drawer"]')
        ->assertSeeIn('[data-testid="post-drawer-title"]', 'Browser Drawer Test Post');
});
