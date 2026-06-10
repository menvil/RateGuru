<?php

namespace App\Actions\Follows;

use App\Models\Follow;
use App\Models\User;
use App\Support\Follows\ToggleFollowAuthorResult;

final class ToggleFollowAuthorAction
{
    public function __construct(
        private readonly FollowAuthorAction $followAction,
        private readonly UnfollowAuthorAction $unfollowAction,
    ) {}

    public function handle(User $follower, User $author): ToggleFollowAuthorResult
    {
        $isFollowing = Follow::query()
            ->where('follower_id', $follower->id)
            ->where('author_id', $author->id)
            ->exists();

        if ($isFollowing) {
            $this->unfollowAction->handle($follower, $author);

            return new ToggleFollowAuthorResult(isFollowing: false);
        }

        $this->followAction->handle($follower, $author);

        return new ToggleFollowAuthorResult(isFollowing: true);
    }
}
