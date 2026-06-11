<?php

use App\Models\User;

it('renders profile 2 page structure', function () {
    $user = User::factory()->create(['username' => 'ivan']);

    $this->get(route('profile.show', $user->username))
        ->assertOk()
        ->assertSee('data-testid="profile-page"', false)
        ->assertSee('data-testid="profile-header"', false)
        ->assertSee('data-testid="profile-tabs"', false);
});

it('uses theme tokens on profile page', function () {
    $user = User::factory()->create(['username' => 'ivan']);

    $this->get(route('profile.show', $user->username))
        ->assertOk()
        ->assertSee('rg-', false);
});
