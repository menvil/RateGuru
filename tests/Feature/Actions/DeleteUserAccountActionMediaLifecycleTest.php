<?php

use App\Actions\Profile\DeleteUserAccountAction;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\User;

it('soft-deletes the account owner\'s avatar asset once the account is gone', function () {
    $avatar = MediaAsset::factory()->avatar()->create();
    $user = User::factory()->create(['avatar_asset_id' => $avatar->id]);

    app(DeleteUserAccountAction::class)->execute($user);

    expect(User::find($user->id))->toBeNull();
    expect(MediaAsset::withTrashed()->find($avatar->id)->trashed())->toBeTrue();
});

it('soft-deletes the image assets of every post the deleted user owned', function () {
    $user = User::factory()->create();
    $image = MediaAsset::factory()->postImage()->create();
    Post::factory()->published()->create(['user_id' => $user->id, 'image_asset_id' => $image->id]);

    app(DeleteUserAccountAction::class)->execute($user);

    expect(MediaAsset::withTrashed()->find($image->id)->trashed())->toBeTrue();
});

it('leaves a still-owned asset alone: an asset another user references is never soft-deleted', function () {
    $sharedPathHolder = MediaAsset::factory()->avatar()->create();
    $deletedUser = User::factory()->create(['avatar_asset_id' => $sharedPathHolder->id]);

    // A second, unrelated user's avatar points at a *different* asset — the
    // real invariant under test is that deleting the first user never
    // touches media it doesn't own, not this specific asset in particular.
    $otherAsset = MediaAsset::factory()->avatar()->create();
    $otherUser = User::factory()->create(['avatar_asset_id' => $otherAsset->id]);

    app(DeleteUserAccountAction::class)->execute($deletedUser);

    expect(MediaAsset::find($otherAsset->id))->not->toBeNull()
        ->and(MediaAsset::find($otherAsset->id)->trashed())->toBeFalse()
        ->and(User::find($otherUser->id))->not->toBeNull();
});

it('does not soft-delete a user\'s own former avatar asset if it somehow remains referenced by something else', function () {
    // Contrived but exercises the safety recheck: an asset id that shows up
    // in the "about to be released" set but is still referenced by another
    // row at release time must not be soft-deleted.
    $asset = MediaAsset::factory()->postImage()->create();
    $user = User::factory()->create(['avatar_asset_id' => $asset->id]);
    Post::factory()->published()->create(['image_asset_id' => $asset->id]); // still referenced by an unrelated post

    app(DeleteUserAccountAction::class)->execute($user);

    expect(MediaAsset::find($asset->id))->not->toBeNull()
        ->and(MediaAsset::find($asset->id)->trashed())->toBeFalse();
});

it('completes account deletion even when there is no avatar or posts to release', function () {
    $user = User::factory()->create(['avatar_asset_id' => null]);

    app(DeleteUserAccountAction::class)->execute($user);

    expect(User::find($user->id))->toBeNull();
});

it('never re-throws and still completes the account deletion if releasing an asset unexpectedly fails', function () {
    $avatar = MediaAsset::factory()->avatar()->create();
    $user = User::factory()->create(['avatar_asset_id' => $avatar->id]);

    // Simulates a cleanup-time failure (e.g. a transient DB hiccup while
    // soft-deleting the now-unreferenced asset) — the account deletion
    // itself must not fail because of it. SoftDeletes::delete() updates via
    // the query builder directly (never fires saving/updating), so the
    // 'deleting' event is the one that actually fires here.
    MediaAsset::deleting(function (): void {
        throw new RuntimeException('Simulated release failure.');
    });

    try {
        app(DeleteUserAccountAction::class)->execute($user);
    } finally {
        MediaAsset::flushEventListeners();
    }

    expect(User::find($user->id))->toBeNull();
});
