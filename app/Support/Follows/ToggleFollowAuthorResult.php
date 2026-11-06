<?php

namespace App\Support\Follows;

final readonly class ToggleFollowAuthorResult
{
    public function __construct(public readonly bool $isFollowing) {}
}
