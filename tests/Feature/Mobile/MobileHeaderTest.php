<?php

use App\Models\User;

it('renders mobile-safe header controls', function () {
    $this->get(route('feed'))
        ->assertOk()
        ->assertSee('data-testid="app-header"', false);
});

it('renders language switcher with mobile testid', function () {
    $this->get(route('feed'))
        ->assertOk()
        ->assertSee('data-testid="language-switcher"', false);
});

it('renders theme switcher accessible to guest on mobile', function () {
    $this->get(route('feed'))
        ->assertOk()
        ->assertSee('data-testid="theme-switcher"', false);
});

it('renders authenticated header with compact upload button on mobile', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('feed'))
        ->assertOk()
        ->assertSee('data-testid="open-upload-button"', false);
});
