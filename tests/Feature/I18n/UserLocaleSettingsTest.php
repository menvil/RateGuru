<?php

use App\Livewire\Settings\UserLocaleSettings;
use App\Models\User;
use Livewire\Livewire;

it('allows authenticated user to update locale preference', function (string $locale) {
    $user = User::factory()->create(['locale' => 'en']);

    Livewire::actingAs($user)
        ->test(UserLocaleSettings::class)
        ->set('locale', $locale)
        ->call('save')
        ->assertHasNoErrors();

    expect($user->fresh()->locale)->toBe($locale);
})->with(translatedLocales());

it('rejects unsupported user locale preference', function () {
    $user = User::factory()->create(['locale' => 'en']);

    Livewire::actingAs($user)
        ->test(UserLocaleSettings::class)
        ->set('locale', unsupportedLocale())
        ->call('save')
        ->assertHasErrors('locale');
});

it('renders user locale settings on profile page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertSee(__('ui.settings.language'));
});
