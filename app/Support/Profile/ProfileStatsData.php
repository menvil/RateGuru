<?php

namespace App\Support\Profile;

final readonly class ProfileStatsData
{
    public function __construct(
        public int $publicPostsCount,
        public int $followersCount,
        public int $followingCount,
        public ?int $savedPostsCount,
    ) {}
}
