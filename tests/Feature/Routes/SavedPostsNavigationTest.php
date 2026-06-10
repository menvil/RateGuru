<?php

use App\Models\ProjectSettings;
use App\Models\User;

it('shows saved posts navigation entry to authenticated users when feature is enabled', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => true]]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('feed'))
        ->assertOk()
        ->assertSee('data-testid="nav-saved-posts"', false);
});

it('hides saved posts navigation entry when feature is disabled', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => false]]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('feed'))
        ->assertOk()
        ->assertDontSee('data-testid="nav-saved-posts"', false);
});

it('does not show saved posts navigation entry to guests', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => true]]);

    $this->get(route('feed'))
        ->assertOk()
        ->assertDontSee('data-testid="nav-saved-posts"', false);
});
