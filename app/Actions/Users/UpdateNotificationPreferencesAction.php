<?php

namespace App\Actions\Users;

use App\Models\Concerns\LocksActorForWrite;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class UpdateNotificationPreferencesAction
{
    use LocksActorForWrite;

    public function handle(User $user, bool $notifyFollowedAuthorPosts): void
    {
        // Private-preference write: living accounts only; a stale request
        // must never persist into a Deleted tombstone.
        DB::transaction(function () use ($user, $notifyFollowedAuthorPosts): void {
            $locked = $this->lockActor($user);

            if ($locked === null || ! $locked->canAuthenticate()) {
                return;
            }

            $locked->forceFill([
                'notify_followed_author_posts' => $notifyFollowedAuthorPosts,
            ])->save();

            $user->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
