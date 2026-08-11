<?php

use App\Actions\Profile\DeleteUserAccountAction;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\User;
use App\Services\Media\MediaReferenceChecker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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

it('soft-deletes the image asset of a post the user had already soft-deleted themselves', function () {
    // The post is soft-deleted (restorable) *before* the account deletion —
    // users.posts()'s default query scope would silently exclude it, which
    // would leave its image active-and-unreferenced forever, since it's
    // about to be hard-cascade-deleted along with the account and never
    // gets a chance to be captured any other way.
    $user = User::factory()->create();
    $image = MediaAsset::factory()->postImage()->create();
    $post = Post::factory()->published()->create(['user_id' => $user->id, 'image_asset_id' => $image->id]);
    $post->delete();

    expect($post->trashed())->toBeTrue();

    app(DeleteUserAccountAction::class)->execute($user);

    expect(MediaAsset::withTrashed()->find($image->id)->trashed())->toBeTrue();
});

it('leaves a still-owned asset alone: an asset another user references is never soft-deleted', function () {
    $sharedAsset = MediaAsset::factory()->avatar()->create();
    $deletedUser = User::factory()->create(['avatar_asset_id' => $sharedAsset->id]);

    // A second, still-active user references the *same* asset — the real
    // invariant under test is that a reference from someone other than the
    // deleted user protects the asset, not merely that unrelated assets
    // are left alone (which is trivially true either way). Nothing in this
    // schema stops two rows pointing avatar_asset_id/image_asset_id at the
    // same media_assets row (no uniqueness constraint on either column), so
    // this sharing scenario is a real, reachable state, not a fabricated one.
    $otherUser = User::factory()->create(['avatar_asset_id' => $sharedAsset->id]);

    app(DeleteUserAccountAction::class)->execute($deletedUser);

    expect(MediaAsset::find($sharedAsset->id))->not->toBeNull()
        ->and(MediaAsset::find($sharedAsset->id)->trashed())->toBeFalse()
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

it('does not disturb an asset that was already soft-deleted before the account is deleted', function () {
    // Simulates DB state that could legitimately exist independently of
    // this account deletion (e.g. the asset was already released by an
    // earlier, unrelated cleanup) — the bulk update must never reset an
    // already-trashed asset's grace-period clock, and the transaction as a
    // whole must complete normally rather than treating this as an error.
    $asset = MediaAsset::factory()->avatar()->create();
    $asset->delete();
    $originalDeletedAt = $asset->fresh()->deleted_at;

    $user = User::factory()->create(['avatar_asset_id' => $asset->id]);

    app(DeleteUserAccountAction::class)->execute($user);

    expect(User::find($user->id))->toBeNull();

    $asset->refresh();
    expect($asset->trashed())->toBeTrue()
        ->and($asset->deleted_at->equalTo($originalDeletedAt))->toBeTrue();
});

it('releases media in a bounded number of queries, not one per asset, when the deleted user owns many', function () {
    $user = User::factory()->create();
    $avatar = MediaAsset::factory()->avatar()->create();
    $user->update(['avatar_asset_id' => $avatar->id]);

    foreach (range(1, 15) as $i) {
        $image = MediaAsset::factory()->postImage()->create();
        Post::factory()->published()->create(['user_id' => $user->id, 'image_asset_id' => $image->id]);
    }

    $queryCount = 0;
    DB::listen(function () use (&$queryCount): void {
        $queryCount++;
    });

    app(DeleteUserAccountAction::class)->execute($user);

    // lockForUpdate() reload (1) + capture (1) + delete (1) +
    // referencedAssetIds()'s two whereIn() queries (2) + one bulk
    // soft-delete update (1) = 6 (BEGIN/COMMIT never reach DB::listen() for
    // the outermost transaction). A small, fixed number regardless of
    // asset count — the old per-asset release loop would have scaled with
    // all 16 assets involved (avatar + 15 post images) instead, well past
    // this threshold.
    expect($queryCount)->toBeLessThan(8);

    // The bulk update genuinely released everything, not just a subset —
    // the query-count assertion alone wouldn't catch a "fast but wrong"
    // regression (e.g. only releasing the first matched row).
    expect(MediaAsset::withTrashed()->whereNotNull('deleted_at')->count())->toBe(16);
});

it('releases a single asset in the same bounded query budget as many, confirming the count does not grow linearly', function () {
    $avatar = MediaAsset::factory()->avatar()->create();
    $user = User::factory()->create(['avatar_asset_id' => $avatar->id]);

    $queryCount = 0;
    DB::listen(function () use (&$queryCount): void {
        $queryCount++;
    });

    app(DeleteUserAccountAction::class)->execute($user);

    // Same 6-query budget as the many-asset case above — the query count
    // is driven by the number of *operations*, not the number of assets.
    expect($queryCount)->toBeLessThan(8);
    expect(MediaAsset::withTrashed()->find($avatar->id)->trashed())->toBeTrue();
});

it('completes account deletion even when there is no avatar or posts to release', function () {
    $user = User::factory()->create(['avatar_asset_id' => null]);

    app(DeleteUserAccountAction::class)->execute($user);

    expect(User::find($user->id))->toBeNull();
});

it('rolls back the entire account deletion, including the already-cascaded post, if the media release step fails', function () {
    // Closes the crash gap: the account delete and the media release used
    // to be two independent steps, so a failure between them (or during
    // the release itself, which used to swallow its own exceptions) could
    // leave the user deleted with its media stuck active-and-unreferenced
    // forever — media:purge's sweep only ever considers already-trashed
    // rows, so nothing would ever pick that state up. Injecting the
    // failure via a bound MediaReferenceChecker double (real dependency
    // injection, not reflection) proves the whole DB::transaction() around
    // both steps genuinely rolls back end to end: the user row, the
    // cascade-deleted post, and both media assets all land back exactly
    // where they started.
    $avatar = MediaAsset::factory()->avatar()->create();
    $user = User::factory()->create(['avatar_asset_id' => $avatar->id]);
    $image = MediaAsset::factory()->postImage()->create();
    $post = Post::factory()->published()->create(['user_id' => $user->id, 'image_asset_id' => $image->id]);

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

    expect(User::find($user->id))->not->toBeNull();
    expect(Post::find($post->id))->not->toBeNull();
    expect(MediaAsset::find($avatar->id)->trashed())->toBeFalse();
    expect(MediaAsset::find($image->id)->trashed())->toBeFalse();
});
