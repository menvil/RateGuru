<?php

use App\Actions\Profile\DeleteUserAccountAction;
use App\Enums\UserStatus;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\User;
use App\Services\Media\MediaReferenceChecker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/*
 * Media semantics of account deletion after the tombstone refactor: only
 * the avatar (identity media) is detached and released; post images belong
 * to the posts, which survive their author, so they must remain active.
 */

it('keeps the image of a post the user had soft-deleted before account deletion', function () {
    // The post stays restorable-or-purgeable under its own lifecycle
    // (PR-E); account deletion must not decide its image's fate anymore.
    $user = User::factory()->create();
    $image = MediaAsset::factory()->postImage()->create();
    $post = Post::factory()->published()->create(['user_id' => $user->id, 'image_asset_id' => $image->id]);
    $post->delete();

    app(DeleteUserAccountAction::class)->execute($user);

    expect(Post::withTrashed()->find($post->id))->not->toBeNull();
    expect(MediaAsset::find($image->id)->trashed())->toBeFalse();
});

it('does not disturb an asset that was already soft-deleted before the account is deleted', function () {
    $avatar = MediaAsset::factory()->avatar()->create();
    $user = User::factory()->create(['avatar_asset_id' => $avatar->id]);

    $avatar->delete();
    $originalDeletedAt = MediaAsset::withTrashed()->find($avatar->id)->deleted_at;

    app(DeleteUserAccountAction::class)->execute($user);

    expect(MediaAsset::withTrashed()->find($avatar->id)->deleted_at->equalTo($originalDeletedAt))->toBeTrue();
});

it('leaves the session authenticated if the transaction rolls back before logout runs', function () {
    $avatar = MediaAsset::factory()->avatar()->create();
    $user = User::factory()->create(['avatar_asset_id' => $avatar->id]);
    $this->actingAs($user);

    $originalChecker = app(MediaReferenceChecker::class);

    app()->instance(MediaReferenceChecker::class, new class extends MediaReferenceChecker
    {
        public function referencedAssetIds(Collection $assetIds): Collection
        {
            throw new RuntimeException('Simulated media release failure.');
        }
    });

    try {
        expect(fn () => app(DeleteUserAccountAction::class)->execute($user))
            ->toThrow(RuntimeException::class, 'Simulated media release failure.');
    } finally {
        app()->instance(MediaReferenceChecker::class, $originalChecker);
    }

    $this->assertAuthenticated();
    expect($user->fresh()->status)->toBe(UserStatus::Active);
});

it('logs out the current session once the tombstone actually commits', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    app(DeleteUserAccountAction::class)->execute($user);

    $this->assertGuest();
    expect($user->fresh()->status)->toBe(UserStatus::Deleted);
});

it('never logs out if an outer transaction wrapping execute() itself rolls back after it returns', function () {
    // execute()'s own DB::transaction() only ever releases a savepoint when
    // nested inside someone else's transaction — the logout must stay
    // registered against the real outermost commit and be discarded when
    // that outer transaction rolls back, together with the tombstone.
    $user = User::factory()->create();
    $this->actingAs($user);

    try {
        DB::transaction(function () use ($user): void {
            app(DeleteUserAccountAction::class)->execute($user);

            throw new RuntimeException('Outer transaction failure after execute() returned.');
        });
    } catch (RuntimeException) {
        // Expected.
    }

    $this->assertAuthenticated();
    expect($user->fresh()->status)->toBe(UserStatus::Active);
});
