<?php

use App\Models\User;

it('renders public profile tabs', function () {
    $user = User::factory()->create(['username' => 'ivan']);

    $this->get(route('profile.show', $user->username))
        ->assertOk()
        ->assertSee('data-testid="profile-tab-posts"', false);
});

it('shows activity tab publicly', function () {
    $user = User::factory()->create(['username' => 'ivan']);

    $this->get(route('profile.show', $user->username))
        ->assertOk()
        ->assertSee('data-testid="profile-tab-activity"', false);
});

it('shows saved tab only to profile owner', function () {
    $owner = User::factory()->create(['username' => 'owner']);

    $this->actingAs($owner)
        ->get(route('profile.show', $owner->username))
        ->assertSee('data-testid="profile-tab-saved"', false);
});

it('hides saved tab from other users', function () {
    $owner = User::factory()->create(['username' => 'owner']);
    $viewer = User::factory()->create();

    $this->actingAs($viewer)
        ->get(route('profile.show', $owner->username))
        ->assertDontSee('data-testid="profile-tab-saved"', false);
});

it('hides saved tab from guests', function () {
    $owner = User::factory()->create(['username' => 'owner']);

    $this->get(route('profile.show', $owner->username))
        ->assertDontSee('data-testid="profile-tab-saved"', false);
});

it('posts tab is active by default', function () {
    $user = User::factory()->create(['username' => 'ivan']);

    $this->get(route('profile.show', $user->username))
        ->assertOk()
        ->assertSee('data-testid="profile-posts-tab"', false);
});

it('does not show saved tab content to other users', function () {
    $owner = User::factory()->create(['username' => 'owner']);
    $viewer = User::factory()->create();

    $this->actingAs($viewer)
        ->get(route('profile.show', ['username' => $owner->username, 'tab' => 'saved']))
        ->assertDontSee('data-testid="profile-saved-tab"', false);
});
