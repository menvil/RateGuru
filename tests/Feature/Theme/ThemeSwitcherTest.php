<?php

use App\Livewire\Theme\ThemeSwitcher;
use App\Models\User;

it('renders theme switcher on feed page', function () {
    $this->get(route('feed'))
        ->assertSee('data-testid="theme-switcher"', false);
});

it('theme switcher has test ids for all options', function () {
    $this->get(route('feed'))
        ->assertSee('data-testid="theme-option-system"', false)
        ->assertSee('data-testid="theme-option-light"', false)
        ->assertSee('data-testid="theme-option-dark"', false);
});

it('theme switcher has aria labels for all options', function () {
    $component = Livewire\Livewire::test(ThemeSwitcher::class);

    $component->assertSee('aria-label="System"', false)
        ->assertSee('aria-label="Light"', false)
        ->assertSee('aria-label="Dark"', false);
});

it('mounts with system preference by default for guest', function () {
    Livewire\Livewire::test(ThemeSwitcher::class)
        ->assertSet('preference', 'system');
});

it('mounts with user preference for authenticated user', function () {
    $user = User::factory()->create(['theme_preference' => 'light']);

    Livewire\Livewire::actingAs($user)
        ->test(ThemeSwitcher::class)
        ->assertSet('preference', 'light');
});
