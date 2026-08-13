<?php

namespace App\Actions\Follows;

use App\Exceptions\Follows\CannotFollowAuthorException;
use App\Exceptions\Follows\CannotFollowSelfException;
use App\Exceptions\Follows\FollowFeatureDisabledException;
use App\Models\Follow;
use App\Models\User;
use App\Support\Observability\DomainLogger;
use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Support\Facades\DB;

final class FollowAuthorAction
{
    public function __construct(
        private readonly ProjectSettingsManager $settings,
        private readonly DomainLogger $logger,
    ) {}

    public function handle(User $follower, User $author): void
    {
        if (! $this->settings->current()->featureFlag('show_follow_buttons')) {
            $this->logger->security('security.feature_disabled_action_attempted', [
                'feature' => 'show_follow_buttons',
                'action' => 'follow',
                'user_id' => $follower->id,
            ]);
            throw new FollowFeatureDisabledException;
        }

        if ($follower->id === $author->id) {
            throw new CannotFollowSelfException;
        }

        // Cheap pre-checks; the locked rows below are authoritative.
        if (! $follower->canFollow()) {
            throw CannotFollowAuthorException::followerNotAllowed();
        }

        if (! $author->canBeFollowed()) {
            throw CannotFollowAuthorException::authorNotViewable();
        }

        DB::transaction(function () use ($follower, $author): void {
            // Two-user operation: lock both rows in ascending primary-key
            // order (the uniform deterministic order for user pairs, see
            // docs/architecture/user-lifecycle.md), then identify
            // follower/target — never lock "follower first" or "author
            // first", which would deadlock against the opposite pairing.
            $locked = User::query()
                ->whereIn('id', [$follower->id, $author->id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $lockedFollower = $locked->get($follower->id);
            $lockedAuthor = $locked->get($author->id);

            if ($lockedFollower === null || ! $lockedFollower->canFollow()) {
                throw CannotFollowAuthorException::followerNotAllowed();
            }

            if ($lockedAuthor === null || ! $lockedAuthor->canBeFollowed()) {
                throw CannotFollowAuthorException::authorNotViewable();
            }

            Follow::query()->firstOrCreate([
                'follower_id' => $follower->id,
                'author_id' => $author->id,
            ]);
        });

        $this->logger->info('follows.followed', ['user_id' => $follower->id, 'author_id' => $author->id]);
    }
}
