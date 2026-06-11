<?php

namespace App\Support\Profile;

use Carbon\Carbon;

final readonly class UserPublicProfile
{
    public function __construct(
        public int $id,
        public string $username,
        public string $displayName,
        public ?string $avatarUrl,
        public ?string $bio,
        public ?string $websiteUrl,
        public Carbon $joinedAt,
    ) {}
}
