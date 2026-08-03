<?php

use App\Actions\Profile\UpdateUserProfileAction;
use App\Enums\MediaKind;
use App\Enums\MediaStatus;
use App\Enums\MediaVisibility;
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
        ->and($asset->status)->toBe(MediaStatus::Ready)
        ->and($asset->visibility)->toBe(MediaVisibility::Public);

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
    Storage::fake('public');

    $user = User::factory()->withAvatar()->create();
    $existingAvatarAssetId = $user->avatar_asset_id;

    Livewire::actingAs($user)
        ->test(EditProfileForm::class)
        ->set('display_name', 'Ivan')
        ->call('save')
        ->assertHasNoErrors();

    expect($user->fresh()->avatar_asset_id)->toBe($existingAvatarAssetId);

    // The FK staying the same isn't enough on its own — the referenced
    // asset must also remain active, or resolved_avatar_url silently
    // breaks because avatarAsset excludes soft-deleted rows.
    expect(MediaAsset::query()->find($existingAvatarAssetId))->not->toBeNull();
    expect($user->fresh()->resolved_avatar_url)->not->toBeNull();
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

it('deletes the newly stored avatar file when the database transaction fails', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('avatar.jpg', 400, 400);

    expect(fn () => app(UpdateUserProfileAction::class)->execute(
        $user,
        ['rating_activity_visibility' => 'not_a_real_value'],
        $file,
    ))->toThrow(ValueError::class);

    expect($user->fresh()->avatar_asset_id)->toBeNull();

    // The file was written before the transaction failed; the action must
    // compensate by removing it instead of leaving an orphaned upload.
    expect(Storage::disk('public')->allFiles('avatars'))->toBeEmpty();
});

it('leaves the previous avatar untouched when the database transaction fails during replacement', function () {
    Storage::fake('public');

    $user = User::factory()->withAvatar()->create();
    $previousAssetId = $user->avatar_asset_id;
    $file = UploadedFile::fake()->image('new-avatar.jpg', 400, 400);

    expect(fn () => app(UpdateUserProfileAction::class)->execute(
        $user,
        ['rating_activity_visibility' => 'not_a_real_value'],
        $file,
    ))->toThrow(ValueError::class);

    $user = $user->fresh();
    expect($user->avatar_asset_id)->toBe($previousAssetId);
    expect(MediaAsset::withTrashed()->find($previousAssetId)->trashed())->toBeFalse();

    // The newly stored file must be cleaned up; only the previous avatar's
    // asset remains.
    expect(MediaAsset::query()->count())->toBe(1);
});

it('does not orphan an intermediate avatar asset when two replacements race on stale user instances', function () {
    Storage::fake('public');

    $user = User::factory()->withAvatar()->create();
    $originalAssetId = $user->avatar_asset_id;

    // Simulate two requests that both loaded the user before either one's
    // update committed — neither has observed the other's change yet.
    $staleForRequestA = $user->fresh();
    $staleForRequestB = $user->fresh();

    app(UpdateUserProfileAction::class)->execute(
        $staleForRequestA,
        ['rating_activity_visibility' => 'private'],
        UploadedFile::fake()->image('a.jpg', 200, 200),
    );
    $assetAId = $staleForRequestA->avatar_asset_id;

    app(UpdateUserProfileAction::class)->execute(
        $staleForRequestB,
        ['rating_activity_visibility' => 'private'],
        UploadedFile::fake()->image('b.jpg', 200, 200),
    );
    $assetBId = $staleForRequestB->avatar_asset_id;

    expect($user->fresh()->avatar_asset_id)->toBe($assetBId);

    // Every superseded asset must end up soft-deleted — none may remain
    // active-but-unreferenced (orphaned).
    expect(MediaAsset::withTrashed()->find($originalAssetId)->trashed())->toBeTrue();
    expect(MediaAsset::withTrashed()->find($assetAId)->trashed())->toBeTrue();
});
