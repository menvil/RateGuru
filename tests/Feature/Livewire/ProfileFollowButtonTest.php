<?php

use App\Models\ProjectSettings;
use App\Models\User;

it('shows follow button on another users profile', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_follow_buttons' => true]]);

    $viewer = User::factory()->create();
    $author = User::factory()->create(['username' => 'author-user']);

    $this->actingAs($viewer)
        ->get(route('profile.show', $author->username))
        ->assertOk()
        ->assertSee('data-testid="follow-button"', false);
});

it('does not show follow button on own profile', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_follow_buttons' => true]]);

    $user = User::factory()->create(['username' => 'own-user']);

    $this->actingAs($user)
        ->get(route('profile.show', $user->username))
        ->assertOk()
        ->assertDontSee('data-testid="follow-button"', false);
});

it('does not show follow button when feature flag is disabled', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_follow_buttons' => false]]);

    $viewer = User::factory()->create();
    $author = User::factory()->create(['username' => 'author-user2']);

    $this->actingAs($viewer)
        ->get(route('profile.show', $author->username))
        ->assertOk()
        ->assertDontSee('data-testid="follow-button"', false);
});
