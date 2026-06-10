<?php

namespace App\Actions\Follows;

use App\Enums\UserStatus;
use App\Exceptions\Follows\CannotFollowAuthorException;
use App\Exceptions\Follows\CannotFollowSelfException;
use App\Exceptions\Follows\FollowFeatureDisabledException;
use App\Models\Follow;
use App\Models\User;
use App\Support\Settings\ProjectSettingsManager;

final class FollowAuthorAction
{
    public function __construct(private readonly ProjectSettingsManager $settings) {}

    public function handle(User $follower, User $author): void
    {
        if (! $this->settings->current()->featureFlag('show_follow_buttons')) {
            throw new FollowFeatureDisabledException;
        }

        if ($follower->id === $author->id) {
            throw new CannotFollowSelfException;
        }

        if ($author->status !== UserStatus::Active) {
            throw CannotFollowAuthorException::authorNotViewable();
        }

        Follow::query()->firstOrCreate([
            'follower_id' => $follower->id,
            'author_id' => $author->id,
        ]);
    }
}
