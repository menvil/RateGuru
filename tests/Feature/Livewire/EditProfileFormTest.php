<?php

use App\Enums\ProfileActivityVisibility;
use App\Livewire\Profile\EditProfileForm;
use App\Models\User;
use Livewire\Livewire;

it('allows user to update profile fields', function () {
    $user = User::factory()->create([
        'display_name' => null,
        'bio' => null,
    ]);

    Livewire::actingAs($user)
        ->test(EditProfileForm::class)
        ->set('display_name', 'Ivan')
        ->set('bio', 'Profile bio')
        ->call('save')
        ->assertHasNoErrors();

    expect($user->fresh()->display_name)->toBe('Ivan');
    expect($user->fresh()->bio)->toBe('Profile bio');
});

it('allows user to update website url', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(EditProfileForm::class)
        ->set('profile_website_url', 'https://example.com')
        ->call('save')
        ->assertHasNoErrors();

    expect($user->fresh()->profile_website_url)->toBe('https://example.com');
});

it('allows user to update rating activity visibility', function () {
    $user = User::factory()->create(['rating_activity_visibility' => 'private']);

    Livewire::actingAs($user)
        ->test(EditProfileForm::class)
        ->set('rating_activity_visibility', 'public')
        ->call('save')
        ->assertHasNoErrors();

    expect($user->fresh()->rating_activity_visibility)->toBe(ProfileActivityVisibility::Public);
});

it('mounts with current profile values', function () {
    $user = User::factory()->create([
        'display_name' => 'Test Name',
        'bio' => 'Test Bio',
        'rating_activity_visibility' => 'public',
    ]);

    Livewire::actingAs($user)
        ->test(EditProfileForm::class)
        ->assertSet('display_name', 'Test Name')
        ->assertSet('bio', 'Test Bio')
        ->assertSet('rating_activity_visibility', 'public');
});

it('rejects display name exceeding max length', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(EditProfileForm::class)
        ->set('display_name', str_repeat('A', 120))
        ->call('save')
        ->assertHasErrors(['display_name']);
});

it('rejects invalid website url', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(EditProfileForm::class)
        ->set('profile_website_url', 'not-a-url')
        ->call('save')
        ->assertHasErrors(['profile_website_url']);
});

it('rejects invalid visibility value', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(EditProfileForm::class)
        ->set('rating_activity_visibility', 'followers_only')
        ->call('save')
        ->assertHasErrors(['rating_activity_visibility']);
});

it('renders edit profile form on profile edit page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('data-testid="edit-profile-form"', false);
});
