<?php

namespace App\Support\Media;

use App\Models\User;
use App\Services\Media\MediaUrlResolver;

/**
 * The only thing that knows a user's avatar comes from avatarAsset — callers
 * never touch disk/path/Storage themselves. Returns null when there is no
 * avatar; rendering a fallback (initials, generated avatar, etc.) is a
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
        return $this->resolver->publicUrlOrNull($user->avatarAsset);
    }
}
