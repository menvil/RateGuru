<?php

use App\Livewire\Theme\ThemeSwitcher;
use App\Models\User;

it('saves authenticated user theme preference', function () {
    $user = User::factory()->create([
        'theme_preference' => null,
    ]);

    Livewire\Livewire::actingAs($user)
        ->test(ThemeSwitcher::class)
        ->call('setThemePreference', 'dark')
        ->assertHasNoErrors();

    expect($user->fresh()->theme_preference)->toBe('dark');
});

it('rejects invalid user theme preference', function () {
    $user = User::factory()->create();

    Livewire\Livewire::actingAs($user)
        ->test(ThemeSwitcher::class)
        ->call('setThemePreference', 'neon')
        ->assertHasErrors(['preference']);
});

it('dispatches theme preference changed event', function () {
    $user = User::factory()->create(['theme_preference' => null]);

    Livewire\Livewire::actingAs($user)
        ->test(ThemeSwitcher::class)
        ->call('setThemePreference', 'light')
        ->assertDispatched('theme-preference-changed');
});

it('does not save theme preference for guest user', function () {
    Livewire\Livewire::test(ThemeSwitcher::class)
        ->call('setThemePreference', 'dark')
        ->assertHasNoErrors()
        ->assertSet('preference', 'dark');
});

it('updates all supported theme preferences', function (string $preference) {
    $user = User::factory()->create(['theme_preference' => null]);

    Livewire\Livewire::actingAs($user)
        ->test(ThemeSwitcher::class)
        ->call('setThemePreference', $preference)
        ->assertHasNoErrors();

    expect($user->fresh()->theme_preference)->toBe($preference);
})->with(['system', 'light', 'dark']);
