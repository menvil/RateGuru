<?php

use App\Actions\Profile\UpdateUserProfileAction;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('purges a replaced avatar only after the grace period, while the new avatar and its file are never touched', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));

    $user = User::factory()->create();
    $firstAvatar = UploadedFile::fake()->image('first.jpg', 400, 400);

    app(UpdateUserProfileAction::class)->execute($user, ['rating_activity_visibility' => 'private'], $firstAvatar);

    $previousAssetId = $user->fresh()->avatar_asset_id;
    $previousAsset = MediaAsset::find($previousAssetId);
    $previousPath = $previousAsset->path;
    Storage::disk('public')->assertExists($previousPath);

    // Replace the avatar — the existing flow soft-deletes the previous
    // asset inline, unchanged by this PR.
    $secondAvatar = UploadedFile::fake()->image('second.jpg', 400, 400);
    app(UpdateUserProfileAction::class)->execute($user->fresh(), ['rating_activity_visibility' => 'private'], $secondAvatar);

    $newAssetId = $user->fresh()->avatar_asset_id;
    $newAsset = MediaAsset::find($newAssetId);
    $newPath = $newAsset->path;

    expect($newAssetId)->not->toBe($previousAssetId)
        ->and(MediaAsset::withTrashed()->find($previousAssetId)->trashed())->toBeTrue();
    Storage::disk('public')->assertExists($previousPath); // still on disk, within grace

    // Within the grace period: media:purge does not touch the old avatar.
    Carbon::setTestNow(Carbon::parse('2026-01-03 12:00:00'));
    $this->artisan('media:purge')->assertExitCode(0);

    Storage::disk('public')->assertExists($previousPath);
    expect(MediaAsset::withTrashed()->find($previousAssetId))->not->toBeNull();

    // Past the grace period: media:purge removes it.
    Carbon::setTestNow(Carbon::parse('2026-01-20 12:00:00'));
    $this->artisan('media:purge')->assertExitCode(0);

    Storage::disk('public')->assertMissing($previousPath);
    expect(MediaAsset::withTrashed()->find($previousAssetId))->toBeNull();

    // The new avatar was never touched at any point in this sequence.
    Storage::disk('public')->assertExists($newPath);
    expect(MediaAsset::find($newAssetId))->not->toBeNull()
        ->and($user->fresh()->avatar_asset_id)->toBe($newAssetId);
});
