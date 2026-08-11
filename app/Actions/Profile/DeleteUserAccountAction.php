<?php

namespace App\Actions\Profile;

use App\Models\User;
use App\Services\Media\MediaLifecycleService;
use Illuminate\Support\Facades\Auth;

final class DeleteUserAccountAction
{
    public function __construct(
        private readonly MediaLifecycleService $lifecycleService,
    ) {}

    public function execute(User $user): void
    {
        Auth::guard('web')->logout();

        // Captured before the delete: users.user_id and comments.user_id
        // both cascadeOnDelete() at the DB level, so every one of this
        // user's posts (and their images) is about to become truly
        // unreferenced — not soft-deleted, gone outright.
        $assetIds = collect([$user->avatar_asset_id])
            ->merge($user->posts()->whereNotNull('image_asset_id')->pluck('image_asset_id'))
            ->filter()
            ->unique()
            ->values();

        $user->delete();

        // Release, don't purge: this only soft-deletes each now-unreferenced
        // asset (starting its grace period) — physical cleanup still waits
        // for media:purge. Best-effort and never throws, so a cleanup
        // hiccup can never turn an already-successful account deletion into
        // a reported failure.
        foreach ($assetIds as $assetId) {
            $this->lifecycleService->releaseIfUnreferenced($assetId);
        }
    }
}
