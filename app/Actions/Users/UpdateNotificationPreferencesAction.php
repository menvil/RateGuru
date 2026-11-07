<?php

namespace App\Actions\Users;

use App\Models\Concerns\LocksActorForWrite;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class UpdateNotificationPreferencesAction
{
    use LocksActorForWrite;

    /** @return bool whether it was written: never for an account that can no longer sign in */
    public function handle(User $user, bool $notifyFollowedAuthorPosts): bool
    {
        // Private-preference write: living accounts only; a stale request
        // must never persist into a Deleted tombstone.
        return DB::transaction(function () use ($user, $notifyFollowedAuthorPosts): bool {
            $locked = $this->lockActor($user);

            if ($locked === null || ! $locked->canAuthenticate()) {
                return false;
            }

            $locked->forceFill([
                'notify_followed_author_posts' => $notifyFollowedAuthorPosts,
            ])->save();

            $user->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }
}
