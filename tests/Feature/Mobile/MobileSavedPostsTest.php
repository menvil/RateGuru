<?php

use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\User;

it('saved posts page renders a mobile-safe container and empty state without horizontal overflow', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => true]]);

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('saved-posts.index'))
        ->assertOk()
        ->assertSee('data-testid="saved-posts-page"', false)
        ->assertSee('data-testid="saved-posts-empty-state"', false);

    expect($response->getContent())->not->toContain('overflow-x-auto');
});

it('save post button renders with rg- theme tokens', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => true]]);

    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    $response = $this->actingAs($user)
        ->get(route('posts.show', $post));

    $response->assertOk();

    $html = $response->getContent();
    $pos = strpos($html, 'data-testid="save-post-button"');
    expect($pos)->not->toBeFalse();
    $buttonContext = substr($html, max(0, $pos - 600), 1200);
    expect($buttonContext)->toContain('rg-');
});
