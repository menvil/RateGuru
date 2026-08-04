<?php

namespace App\Support\Media;

use App\Models\User;
use App\Services\Media\MediaLocation;
use App\Services\Media\MediaUrlResolver;

/**
 * The only thing that knows a user's avatar comes from avatarAsset — callers
 * never touch disk/path/Storage themselves. Reads the asset's own
 * disk/path/visibility and hands the resolver plain data — the resolver
 * itself never touches the avatarAsset relation. Returns null when there is
 * no avatar; rendering a fallback (initials, generated avatar, etc.) is a
 * presentation-layer concern this class deliberately knows nothing about —
 * see resources/views/components/ui/avatar.blade.php.
 */
final class AvatarUrlResolver
{
    public function __construct(
        private readonly MediaUrlResolver $resolver,
    ) {}

    public function url(User $user): ?string
    {
        $asset = $user->avatarAsset;

        if ($asset === null) {
            return null;
        }

        return $this->resolver->publicUrlOrNull(new MediaLocation($asset->disk, $asset->path), $asset->visibility);
    }
}
