<?php

use App\Livewire\Settings\UserLocaleSettings;
use App\Models\User;
use Livewire\Livewire;

it('allows authenticated user to update locale preference', function () {
    $user = User::factory()->create(['locale' => 'en']);

    Livewire::actingAs($user)
        ->test(UserLocaleSettings::class)
        ->set('locale', 'ru')
        ->call('save')
        ->assertHasNoErrors();

    expect($user->fresh()->locale)->toBe('ru');
});

it('rejects unsupported user locale preference', function () {
    $user = User::factory()->create(['locale' => 'en']);

    Livewire::actingAs($user)
        ->test(UserLocaleSettings::class)
        ->set('locale', 'de')
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
