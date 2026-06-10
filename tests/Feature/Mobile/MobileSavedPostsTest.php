<?php

use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\User;

it('saved posts page renders without horizontal overflow classes', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => true]]);

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('saved-posts.index'));

    $response->assertOk();

    $html = $response->getContent();
    expect($html)->not->toContain('overflow-x-auto');
});

it('saved posts page has mobile-safe container class', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => true]]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('saved-posts.index'))
        ->assertOk()
        ->assertSee('data-testid="saved-posts-page"', false);
});

it('save post button renders with rg- theme tokens', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => true]]);

    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    $response = $this->actingAs($user)
        ->get(route('posts.show', $post));

    $response->assertOk();
    expect($response->getContent())->toContain('rg-');
});

it('empty state renders with proper mobile layout on saved posts page', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => true]]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('saved-posts.index'))
        ->assertOk()
        ->assertSee('data-testid="saved-posts-empty-state"', false);
});
