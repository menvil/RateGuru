<?php

namespace App\Services\Media;

use App\Enums\MediaKind;
use App\Enums\MediaVisibility;

/**
 * What to store and where — disk/directory/visibility are resolved by the
 * caller (from config), never guessed or hardcoded by MediaStorage itself.
 */
final readonly class MediaStoreRequest
{
    public function __construct(
        public string $disk,
        public string $directory,
        public MediaKind $kind,
        public MediaVisibility $visibility,
        public ?int $ownerUserId,
        public ?string $explicitPath = null,
    ) {}

    public static function forPostImage(?int $ownerUserId, ?string $explicitPath = null): self
    {
        return new self(
            disk: (string) config('media.disks.public'),
            directory: (string) config('media.directories.post_images'),
            kind: MediaKind::PostImage,
            visibility: MediaVisibility::Public,
            ownerUserId: $ownerUserId,
            explicitPath: $explicitPath,
        );
    }

    public static function forAvatar(int $ownerUserId, ?string $explicitPath = null): self
    {
        return new self(
            disk: (string) config('media.disks.public'),
            directory: (string) config('media.directories.avatars'),
            kind: MediaKind::Avatar,
            visibility: MediaVisibility::Public,
            ownerUserId: $ownerUserId,
            explicitPath: $explicitPath,
        );
    }
}
