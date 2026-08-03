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

        $previousAvatarAsset = null;

        if ($avatar !== null) {
            $previousAvatarAsset = $user->avatarAsset;
            $asset = $this->createAvatarAsset($this->imageStorage->storeAvatar($avatar, $user), $user);
            $update['avatar_asset_id'] = $asset->id;
        }

        $user->update($update);

        // The previous avatar asset is soft-deleted, but its physical file is
        // intentionally left on disk — orphan cleanup is deferred to PR-07.
        $previousAvatarAsset?->delete();

        $this->logger->info(
            $avatar !== null ? 'profile.avatar.updated' : 'profile.updated',
            ['user_id' => $user->id],
        );
    }

    private function createAvatarAsset(StoredMedia $stored, User $user): MediaAsset
    {
        $orientation = $stored->width !== null && $stored->height !== null
            ? $this->orientationClassifier->classify($stored->width, $stored->height)
            : null;

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
            'aspect_ratio' => $stored->width !== null && $stored->height !== null
                ? round($stored->width / $stored->height, 6)
                : null,
            'orientation' => $orientation,
            'status' => MediaStatus::Ready,
            'visibility' => MediaVisibility::Public,
        ]);
    }
}
