<?php

use App\Enums\UserStatus;
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

it('says the preferences were saved until the next change, and nothing for a save that was not written', function () {
    $user = User::factory()->create(['notify_followed_author_posts' => true]);

    $form = Livewire::actingAs($user)->test(NotificationPreferencesForm::class)
        ->assertSet('saved', false)
        ->set('notify_followed_author_posts', false)
        ->call('save')
        ->assertSet('saved', true)
        ->assertSee(__('ui.actions.saved'));

    $form->set('notify_followed_author_posts', true)->assertSet('saved', false)->assertDontSee(__('ui.actions.saved'));

    // An account deleted meanwhile: nothing written, nothing said.
    $user->forceFill(['status' => UserStatus::Deleted])->save();

    $form->call('save')->assertSet('saved', false);

    expect($user->fresh()->notify_followed_author_posts)->toBeFalse();
});

it('sends the box at once, so "Saved" goes as soon as it is changed', function () {
    // A deferred box would reach updated() only with the next save.
    Livewire::actingAs(User::factory()->create())
        ->test(NotificationPreferencesForm::class)
        ->assertSeeHtml('wire:model.live="notify_followed_author_posts"');
});
