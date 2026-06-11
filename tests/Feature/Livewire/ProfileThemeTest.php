<?php

use App\Models\User;

it('renders profile page with theme token classes', function () {
    $user = User::factory()->create(['username' => 'ivan']);

    $this->get(route('profile.show', $user->username))
        ->assertOk()
        ->assertSee('rg-', false);
});

it('uses rg-text token in profile header', function () {
    $user = User::factory()->create(['username' => 'ivan', 'display_name' => 'Ivan Test']);

    $this->get(route('profile.show', $user->username))
        ->assertOk()
        ->assertSee('text-rg-text', false);
});

it('uses rg-border token in profile tabs', function () {
    $user = User::factory()->create(['username' => 'ivan']);

    $this->get(route('profile.show', $user->username))
        ->assertOk()
        ->assertSee('border-rg-border', false);
});

it('shows bio on profile page when set', function () {
    $user = User::factory()->create([
        'username' => 'ivan',
        'bio' => 'My profile biography text',
    ]);

    $this->get(route('profile.show', $user->username))
        ->assertOk()
        ->assertSee('My profile biography text')
        ->assertSee('data-testid="profile-bio"', false);
});

it('shows website link on profile page when set', function () {
    $user = User::factory()->create([
        'username' => 'ivan',
        'profile_website_url' => 'https://example.com',
    ]);

    $this->get(route('profile.show', $user->username))
        ->assertOk()
        ->assertSee('https://example.com')
        ->assertSee('data-testid="profile-website"', false);
});

it('does not show bio element when bio is empty', function () {
    $user = User::factory()->create(['username' => 'ivan', 'bio' => null]);

    $this->get(route('profile.show', $user->username))
        ->assertOk()
        ->assertDontSee('data-testid="profile-bio"', false);
});
