<?php

namespace App\Support\Follows;

use App\Models\User;

final class FollowStateMap
{
    /** @param array<int, true> $followedAuthorIds */
    public function __construct(private readonly array $followedAuthorIds) {}

    public function isFollowing(User $author): bool
    {
        return isset($this->followedAuthorIds[$author->id]);
    }
}
