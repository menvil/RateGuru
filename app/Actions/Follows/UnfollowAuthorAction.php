<?php

namespace App\Actions\Follows;

use App\Exceptions\Follows\FollowFeatureDisabledException;
use App\Models\Follow;
use App\Models\User;
use App\Support\Observability\DomainLogger;
use App\Support\Settings\ProjectSettingsManager;

final class UnfollowAuthorAction
{
    public function __construct(
        private readonly DomainLogger $logger,
        private readonly ProjectSettingsManager $settings,
    ) {}

    public function handle(User $follower, User $author): void
    {
        if (! $this->settings->current()->featureFlag('show_follow_buttons')) {
            throw new FollowFeatureDisabledException;
        }

        Follow::query()
            ->where('follower_id', $follower->id)
            ->where('author_id', $author->id)
            ->delete();

        $this->logger->info('follows.unfollowed', ['user_id' => $follower->id, 'author_id' => $author->id]);
    }
}
