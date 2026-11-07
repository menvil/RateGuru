<?php

use App\Livewire\Theme\ThemeSwitcher;
use App\Models\ProjectSettings;
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

it('shows a theme chosen in another switcher on the page', function () {
    $other = Livewire\Livewire::test(ThemeSwitcher::class, ['layout' => 'dropdown'])->assertSet('preference', 'system');

    Livewire\Livewire::test(ThemeSwitcher::class)
        ->call('setThemePreference', 'dark')
        ->assertDispatched('theme-preference-changed', preference: 'dark');

    $other->dispatch('theme-preference-changed', preference: 'dark')->assertSet('preference', 'dark');

    // Something that is no theme changes nothing.
    $other->dispatch('theme-preference-changed', preference: 'neon')->assertSet('preference', 'dark');
});

it('mounts with user preference for authenticated user', function () {
    $user = User::factory()->create(['theme_preference' => 'light']);

    Livewire\Livewire::actingAs($user)
        ->test(ThemeSwitcher::class)
        ->assertSet('preference', 'light');
});

it('marks the project\'s default theme for someone who chose none, as the page applies it', function (?User $who) {
    ProjectSettings::factory()->create(['default_theme' => 'light']);

    ($who === null ? Livewire\Livewire::test(ThemeSwitcher::class) : Livewire\Livewire::actingAs($who)->test(ThemeSwitcher::class))
        ->assertSet('preference', 'light');
})->with([
    'a guest' => [null],
    'a member with no choice saved' => [fn () => User::factory()->create(['theme_preference' => null])],
]);

it('tells the browser whether the theme on the page is one the account saved', function (?string $saved, string $source) {
    ProjectSettings::factory()->create(['default_theme' => 'dark']);

    $this->actingAs(User::factory()->create(['theme_preference' => $saved]))
        ->get(route('feed'))
        ->assertSee('data-theme-source="'.$source.'"', false);
})->with([
    'a saved choice' => ['light', 'user'],
    'no choice saved' => [null, 'default'],
]);

it('lets a choice saved to the account outrank the one the browser kept', function () {
    $bootstrap = file_get_contents(resource_path('js/theme-bootstrap.js'));
    $navigated = file_get_contents(resource_path('js/app.js'));

    expect($bootstrap)->toContain("root.dataset.themeSource === 'user'")
        ->toContain('localStorage.setItem(STORAGE_KEY, preference)')
        ->and($navigated)->toContain("root.dataset.themeSource === 'user'");
});

it('keeps in the browser only a theme the visitor chose, and follows the system theme once System is chosen on the page', function () {
    $navigated = file_get_contents(resource_path('js/app.js'));
    $bootstrap = file_get_contents(resource_path('js/theme-bootstrap.js'));

    // The navigation handler applies the theme without storing it.
    expect(preg_match("/livewire:navigated', function \\(\\) \\{(.*?)\\n\\}\\);/s", $navigated, $handler))->toBe(1)
        ->and($handler[1])->toContain('rgApplyTheme(pref)')
        ->not->toContain('rgSetTheme')
        ->not->toContain('localStorage.setItem');

    // The system theme is listened to whatever the first preference was.
    expect($bootstrap)->not->toContain("if (preference === 'system' && window.matchMedia)")
        ->toContain("root.dataset.themePreference === 'system'");
});
