<?php

namespace App\Actions\Profile;

use App\Enums\MediaKind;
use App\Enums\MediaStatus;
use App\Enums\MediaVisibility;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Images\ImageStorage;
use App\Services\Images\StoredMedia;
use App\Support\Media\ImageOrientationClassifier;
use App\Support\Observability\DomainLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class UpdateUserProfileAction
{
    public function __construct(
        private readonly DomainLogger $logger,
        private readonly ImageStorage $imageStorage,
        private readonly ImageOrientationClassifier $orientationClassifier,
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
            ? $this->imageStorage->storeAvatar($avatar, $user)
            : null;

        try {
            DB::transaction(function () use ($user, $update, $storedAvatar): void {
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
                    $asset = $this->createAvatarAsset($storedAvatar, $user);
                    $update['avatar_asset_id'] = $asset->id;
                }

                $user->update($update);

                // The previous avatar asset is soft-deleted, but its physical
                // file is intentionally left on disk — orphan cleanup is
                // deferred to PR-07.
                $previousAvatarAsset?->delete();
            });
        } catch (Throwable $exception) {
            if ($storedAvatar !== null) {
                Storage::disk($storedAvatar->disk)->delete($storedAvatar->path);
            }

            throw $exception;
        }

        $this->logger->info(
            $avatar !== null ? 'profile.avatar.updated' : 'profile.updated',
            ['user_id' => $user->id],
        );
    }

    private function createAvatarAsset(StoredMedia $stored, User $user): MediaAsset
    {
        $hasValidDimensions = $stored->width !== null
            && $stored->height !== null
            && $stored->width > 0
            && $stored->height > 0;

        return MediaAsset::create([
            'owner_user_id' => $user->id,
            'kind' => MediaKind::Avatar,
            'disk' => $stored->disk,
            'path' => $stored->path,
            'original_filename' => $stored->originalFilename,
            'mime_type' => $stored->mimeType,
            'extension' => $stored->extension,
            'byte_size' => $stored->byteSize,
            'width' => $stored->width,
            'height' => $stored->height,
            'aspect_ratio' => $hasValidDimensions
                ? round($stored->width / $stored->height, 6)
                : null,
            'orientation' => $hasValidDimensions
                ? $this->orientationClassifier->classify($stored->width, $stored->height)
                : null,
            'status' => MediaStatus::Ready,
            'visibility' => MediaVisibility::Public,
        ]);
    }
}
