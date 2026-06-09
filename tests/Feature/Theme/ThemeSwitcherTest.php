<?php

use App\Livewire\Theme\ThemeSwitcher;
use App\Models\User;

it('renders theme switcher options', function () {
    $this->get(route('feed'))
        ->assertSee('data-testid="theme-switcher"', false)
        ->assertSee('System')
        ->assertSee('Light')
        ->assertSee('Dark');
});

it('theme switcher has test ids for all options', function () {
    $this->get(route('feed'))
        ->assertSee('data-testid="theme-option-system"', false)
        ->assertSee('data-testid="theme-option-light"', false)
        ->assertSee('data-testid="theme-option-dark"', false);
});

it('renders theme switcher as livewire component', function () {
    $html = Livewire\Livewire::test(ThemeSwitcher::class)
        ->assertSee('System')
        ->assertSee('Light')
        ->assertSee('Dark');
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
