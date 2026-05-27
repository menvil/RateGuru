<?php

use App\Models\Post;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('opens report modal for a post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create([
        'title' => 'Browser Report Modal Test Post',
    ]);

    actingAs($user);

    visit(route('feed'))
        ->assertSee('Browser Report Modal Test Post')
        ->click("[data-testid=\"post-actions-menu-{$post->id}\"]")
        ->click('[data-testid="report-button"]')
        ->assertVisible('[data-testid="report-modal"]')
        ->assertSee('Report content');
});
