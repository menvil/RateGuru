<?php

use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\User;

it('shows follow button on post show author block', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_follow_buttons' => true]]);

    $viewer = User::factory()->create();
    $author = User::factory()->create();
    $post = Post::factory()->for($author, 'user')->published()->create();

    $this->actingAs($viewer)
        ->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('data-testid="post-author-follow"', false);
});

it('does not show follow button on own post', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_follow_buttons' => true]]);

    $author = User::factory()->create();
    $post = Post::factory()->for($author, 'user')->published()->create();

    $this->actingAs($author)
        ->get(route('posts.show', $post))
        ->assertOk()
        ->assertDontSee('data-testid="post-author-follow"', false);
});

it('does not show follow button on post show when feature flag disabled', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_follow_buttons' => false]]);

    $viewer = User::factory()->create();
    $author = User::factory()->create();
    $post = Post::factory()->for($author, 'user')->published()->create();

    $this->actingAs($viewer)
        ->get(route('posts.show', $post))
        ->assertOk()
        ->assertDontSee('data-testid="post-author-follow"', false);
});
