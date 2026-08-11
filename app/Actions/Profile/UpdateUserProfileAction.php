<?php

namespace App\Actions\Profile;

use App\Enums\MediaKind;
use App\Jobs\GenerateMediaVariantsJob;
use App\Models\User;
use App\Services\Media\MediaStoreRequest;
use App\Services\Media\StoredMediaCleaner;
use App\Support\Media\ImageUploadStorer;
use App\Support\Media\MediaAssetCreator;
use App\Support\Observability\DomainLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class UpdateUserProfileAction
{
    public function __construct(
        private readonly DomainLogger $logger,
        private readonly ImageUploadStorer $imageUploadStorer,
        private readonly MediaAssetCreator $mediaAssetCreator,
        private readonly StoredMediaCleaner $storedMediaCleaner,
    ) {}

    public function execute(User $user, array $validatedData, ?UploadedFile $avatar): void
    {
        $update = [
            'display_name' => $validatedData['display_name'] ?? null,
            'bio' => $validatedData['bio'] ?? null,
            'profile_website_url' => $validatedData['profile_website_url'] ?? null,
            'rating_activity_visibility' => $validatedData['rating_activity_visibility'] ?? null,
        ];

        // Storing the file is slow, external I/O that doesn't belong inside a
        // DB transaction — do it first, then only touch the database below.
        $storedAvatar = $avatar !== null
            ? $this->imageUploadStorer->store($avatar, MediaStoreRequest::forAvatar($user->id))
            : null;

        $newAvatarAssetId = null;

        try {
            DB::transaction(function () use ($user, $update, $storedAvatar, &$newAvatarAssetId): void {
                // Lock the row so two concurrent avatar replacements can't both
                // read the same "previous" asset — without this, the second
                // request to commit would soft-delete the original asset again
                // and leave the first request's new asset orphaned (referenced
                // by nobody, never cleaned up).
                $previousAvatarAsset = User::query()
                    ->whereKey($user->id)
                    ->lockForUpdate()
                    ->firstOrFail()
                    ->avatarAsset;

                if ($storedAvatar !== null) {
                    $asset = $this->mediaAssetCreator->create($storedAvatar, MediaKind::Avatar, $user);
                    $update['avatar_asset_id'] = $asset->id;
                    $newAvatarAssetId = $asset->id;
                }

                $user->update($update);

                if ($storedAvatar !== null) {
                    // The previous avatar asset is soft-deleted, but its
                    // physical file is intentionally left on disk — orphan
                    // cleanup is deferred to PR-07. Only run this when a
                    // replacement was actually stored — otherwise an
                    // ordinary profile edit with no new avatar would
                    // soft-delete the user's current, unchanged avatar.
                    $previousAvatarAsset?->delete();
                }
            });
        } catch (Throwable $exception) {
            // The file was already written before the transaction started; a
            // DB failure past that point must not leave it orphaned on disk.
            if ($storedAvatar !== null) {
                $this->storedMediaCleaner->deleteIfUnclaimed($storedAvatar);
            }

            throw $exception;
        }

        $this->logger->info(
            $avatar !== null ? 'profile.avatar.updated' : 'profile.updated',
            ['user_id' => $user->id],
        );

        if ($newAvatarAssetId !== null) {
            try {
                // ->afterCommit(): see the identical comment in
                // CreatePostAction — not a behavior change today (no outer
                // transaction wraps this call), just correct if that changes.
                GenerateMediaVariantsJob::dispatch($newAvatarAssetId)->afterCommit();
            } catch (Throwable $exception) {
                report($exception);

                Log::error('Failed to dispatch media variant generation job.', [
                    'user_id' => $user->id,
                    'media_asset_id' => $newAvatarAssetId,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }
    }
}
