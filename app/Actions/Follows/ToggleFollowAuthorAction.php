<?php

namespace App\Actions\Follows;

use App\Exceptions\Follows\FollowFeatureDisabledException;
use App\Models\Follow;
use App\Models\User;
use App\Support\Follows\ToggleFollowAuthorResult;
use App\Support\Settings\ProjectSettingsManager;

final class ToggleFollowAuthorAction
{
    public function __construct(
        private readonly FollowAuthorAction $followAction,
        private readonly UnfollowAuthorAction $unfollowAction,
        private readonly ProjectSettingsManager $settings,
    ) {}

    public function handle(User $follower, User $author): ToggleFollowAuthorResult
    {
        if (! $this->settings->current()->featureFlag('show_follow_buttons')) {
            throw new FollowFeatureDisabledException;
        }

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
