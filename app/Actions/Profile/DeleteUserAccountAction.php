<?php

namespace App\Actions\Profile;

use App\Models\User;
use App\Services\Media\MediaLifecycleService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class DeleteUserAccountAction
{
    public function __construct(
        private readonly MediaLifecycleService $lifecycleService,
    ) {}

    public function execute(User $user): void
    {
        // The account delete and the media release below run as one DB
        // transaction: either both commit — the user (and everything that
        // cascades from it) is gone *and* its now-unreferenced media is
        // soft-deleted — or, if anything in this closure throws (including
        // a media-release failure, which used to be swallowed here),
        // neither happens. That closes the gap where the user delete could
        // succeed but the process crash/fail before releasing its media,
        // leaving an active-but-unreferenced MediaAsset that media:purge's
        // onlyTrashed() sweep would never even see. No filesystem/storage
        // I/O happens anywhere in this transaction — this only ever moves
        // rows from active to soft-deleted; the physical purge still waits
        // for the normal grace period via media:purge.
        DB::transaction(function () use ($user): void {
            // Re-queried and locked (SELECT ... FOR UPDATE) rather than
            // trusting the caller's own $user instance, which may be stale
            // by the time this runs. The lock also closes the race the
            // plain capture-then-delete sequence had: inserting a post with
            // this user_id requires the same posts.user_id foreign key to
            // hold a reference lock on this exact user row (both PostgreSQL
            // and MariaDB/InnoDB enforce this for any FK-constrained
            // insert), so a concurrent "create post" transaction either
            // completes and is captured below before we lock, or blocks
            // until we commit — at which point the user row it referenced
            // no longer exists and its own insert fails its FK constraint,
            // rather than silently creating a post whose image never gets
            // captured here.
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            // Captured strictly after the lock, while the user's posts (and
            // their images) still exist: posts.user_id cascadeOnDelete()
            // fires at the DB level the moment $locked->delete() runs
            // below, hard-deleting every one of this user's posts —
            // restorable-or-not — well outside Post's own SoftDeletes.
            // withTrashed() is required here: a post the user had already
            // soft-deleted themselves is about to be swept away by that
            // same cascade and would never be captured otherwise, leaving
            // its image active-and-unreferenced forever.
            $assetIds = collect([$locked->avatar_asset_id])
                ->merge($locked->posts()->withTrashed()->whereNotNull('image_asset_id')->pluck('image_asset_id'))
                ->filter()
                ->unique()
                ->values();

            $locked->delete();

            // Runs after the cascade delete, in the same transaction: any
            // id in $assetIds still referenced by something else (a shared
            // avatar, another user's post) is left alone; whatever remains
            // unreferenced is soft-deleted here, atomically with the
            // account delete itself — a failure here rolls back the user
            // delete too, rather than leaving a deleted account with
            // orphaned-but-still-active media.
            $this->lifecycleService->releaseUnreferenced($assetIds);
        });

        // Only reached once the transaction above has actually committed —
        // if it throws (a media-release failure rolling everything back),
        // this line never runs, so the caller stays authenticated against
        // an account that, per the DB, was never deleted. Logging out
        // first (the previous behavior) would have left the session logged
        // out even though the account still fully existed.
        Auth::guard('web')->logout();
    }
}
