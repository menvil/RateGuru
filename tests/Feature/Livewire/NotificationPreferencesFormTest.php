<?php

use App\Livewire\Settings\NotificationPreferencesForm;
use App\Models\User;
use Livewire\Livewire;

it('allows user to update followed author post notification preference', function () {
    $user = User::factory()->create([
        'notify_followed_author_posts' => true,
    ]);

    Livewire::actingAs($user)
        ->test(NotificationPreferencesForm::class)
        ->set('notify_followed_author_posts', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($user->fresh()->notify_followed_author_posts)->toBeFalse();
});

it('mounts with current preference value', function () {
    $user = User::factory()->create([
        'notify_followed_author_posts' => false,
    ]);

    Livewire::actingAs($user)
        ->test(NotificationPreferencesForm::class)
        ->assertSet('notify_followed_author_posts', false);
});

it('rejects save when notify_followed_author_posts is not a boolean', function () {
    $user = User::factory()->create(['notify_followed_author_posts' => true]);

    Livewire::actingAs($user)
        ->test(NotificationPreferencesForm::class)
        ->set('notify_followed_author_posts', 'not-a-boolean')
        ->call('save')
        ->assertHasErrors(['notify_followed_author_posts']);
});

it('shows notification preference form on profile edit page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('data-testid="notification-preferences-form"', false);
});
