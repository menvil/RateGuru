<?php

use App\Enums\MediaKind;
use App\Enums\MediaStatus;
use App\Livewire\Profile\EditProfileForm;
use App\Models\MediaAsset;
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

    $user = $user->fresh();
    expect($user->avatar_asset_id)->not->toBeNull();

    $asset = $user->avatarAsset;
    expect($asset->kind)->toBe(MediaKind::Avatar)
        ->and($asset->owner_user_id)->toBe($user->id)
        ->and($asset->disk)->toBe('public')
        ->and($asset->status)->toBe(MediaStatus::Ready);

    Storage::disk('public')->assertExists($asset->path);
});

it('resolves the uploaded avatar through resolved_avatar_url', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('avatar.jpg', 400, 400);

    Livewire::actingAs($user)
        ->test(EditProfileForm::class)
        ->set('avatar', $file)
        ->call('save')
        ->assertHasNoErrors();

    $user = $user->fresh();

    expect($user->resolved_avatar_url)->toBe(Storage::disk('public')->url($user->avatarAsset->path));
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

it('does not change the avatar asset when no avatar is uploaded', function () {
    $user = User::factory()->create(['avatar_asset_id' => null]);

    Livewire::actingAs($user)
        ->test(EditProfileForm::class)
        ->set('display_name', 'Ivan')
        ->call('save')
        ->assertHasNoErrors();

    expect($user->fresh()->avatar_asset_id)->toBeNull();
});

it('soft-deletes the previous avatar asset on replacement but leaves the physical file on disk', function () {
    Storage::fake('public');

    $user = User::factory()->withAvatar()->create();
    $previousAsset = $user->avatarAsset;
    Storage::disk('public')->put($previousAsset->path, 'fake-bytes');

    $file = UploadedFile::fake()->image('new-avatar.jpg', 400, 400);

    Livewire::actingAs($user)
        ->test(EditProfileForm::class)
        ->set('avatar', $file)
        ->call('save')
        ->assertHasNoErrors();

    $user = $user->fresh();
    expect($user->avatar_asset_id)->not->toBe($previousAsset->id);

    // Temporary limitation until PR-07: the record is soft-deleted, but the
    // physical file is deliberately left in place (no orphan cleanup yet).
    expect(MediaAsset::withTrashed()->find($previousAsset->id)->trashed())->toBeTrue();
    Storage::disk('public')->assertExists($previousAsset->path);
});
