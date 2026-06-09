<?php

use App\Models\Post;
use App\Models\ProjectSettings;

it('shows share buttons on published post show page when feature flag enabled', function () {
    ProjectSettings::factory()->create([
        'feature_flags' => [
            'show_share_buttons' => true,
        ],
    ]);

    $post = Post::factory()->published()->create();

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('data-testid="share-buttons"', false);
});

it('hides share buttons on post show when feature flag disabled', function () {
    ProjectSettings::factory()->create([
        'feature_flags' => [
            'show_share_buttons' => false,
        ],
    ]);

    $post = Post::factory()->published()->create();

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertDontSee('data-testid="share-buttons"', false);
});

it('shows copy link and social links on post show', function () {
    ProjectSettings::factory()->create([
        'feature_flags' => [
            'show_share_buttons' => true,
        ],
    ]);

    $post = Post::factory()->published()->create();

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('data-testid="share-copy-link"', false)
        ->assertSee('data-testid="share-facebook"', false)
        ->assertSee('data-testid="share-x"', false);
});
