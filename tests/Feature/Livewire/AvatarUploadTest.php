<?php

use App\Livewire\Profile\EditProfileForm;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('allows user to upload avatar', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $file = UploadedFile::fake()->image('avatar.jpg', 400, 400);

    Livewire::actingAs($user)
        ->test(EditProfileForm::class)
        ->set('avatar', $file)
        ->call('save')
        ->assertHasNoErrors();

    expect($user->fresh()->avatar_path)->not->toBeNull();
    Storage::disk('public')->assertExists($user->fresh()->avatar_path);
});

it('rejects non image avatar upload', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

    Livewire::actingAs($user)
        ->test(EditProfileForm::class)
        ->set('avatar', $file)
        ->call('save')
        ->assertHasErrors(['avatar']);
});

it('rejects oversized avatar upload', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $file = UploadedFile::fake()->image('avatar.jpg', 5000, 5000)->size(6000);

    Livewire::actingAs($user)
        ->test(EditProfileForm::class)
        ->set('avatar', $file)
        ->call('save')
        ->assertHasErrors(['avatar']);
});

it('does not change avatar path when no avatar is uploaded', function () {
    $user = User::factory()->create(['avatar_path' => null]);

    Livewire::actingAs($user)
        ->test(EditProfileForm::class)
        ->set('display_name', 'Ivan')
        ->call('save')
        ->assertHasNoErrors();

    expect($user->fresh()->avatar_path)->toBeNull();
});
