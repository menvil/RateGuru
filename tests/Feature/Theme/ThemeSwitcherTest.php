<?php

use App\Livewire\Theme\ThemeSwitcher;
use App\Models\User;

it('renders the theme switcher on the feed with a test id for every option', function () {
    $this->get(route('feed'))
        ->assertSee('data-testid="theme-switcher"', false)
        ->assertSee('data-testid="theme-option-system"', false)
        ->assertSee('data-testid="theme-option-light"', false)
        ->assertSee('data-testid="theme-option-dark"', false)
        ->assertSee('rg-surface', false);
});

it('mounts for a guest with the system preference and a label on every option', function () {
    Livewire\Livewire::test(ThemeSwitcher::class)
        ->assertSet('preference', 'system')
        ->assertSee('aria-label="System"', false)
        ->assertSee('aria-label="Light"', false)
        ->assertSee('aria-label="Dark"', false);
});

it('mounts with user preference for authenticated user', function () {
    $user = User::factory()->create(['theme_preference' => 'light']);

    Livewire\Livewire::actingAs($user)
        ->test(ThemeSwitcher::class)
        ->assertSet('preference', 'light');
});
