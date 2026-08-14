<?php

namespace App\Actions\Moderation\Concerns;

use App\Enums\PostStatus;
use App\Exceptions\Moderation\CannotModeratePostException;
use App\Models\Concerns\LocksActorForWrite;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Shared locked authorization for the post moderation transitions
 * (Approve/Reject/Hide/Restore). Lock order: Actor User -> Post; both
 * caller instances may be stale — a sanction or a concurrent moderation
 * can commit between the pre-check and the write — so source-status
 * validation and authorization run against the locked rows only.
 */
trait LocksAndAuthorizesPostModeration
{
    use LocksActorForWrite;

    /** @return array{User, Post} the locked [actor, post] */
    private function lockAndAuthorizePostModeration(
        User $moderator,
        Post $post,
        string $ability,
        PostStatus $expectedStatus,
    ): array {
        $lockedActor = $this->lockActor($moderator);

        $locked = $post->newQuery()->lockForUpdate()->find($post->getKey());

        if ($locked === null || $locked->status !== $expectedStatus) {
            throw CannotModeratePostException::becausePostStatusIsInvalid();
        }

        // The moderate-content gate takes no model; policy abilities do.
        $abilityArgs = $ability === 'moderate-content' ? [] : [$locked];

        if ($lockedActor === null || ! Gate::forUser($lockedActor)->allows($ability, $abilityArgs)) {
            throw CannotModeratePostException::becauseUserIsNotAllowed();
        }

        return [$lockedActor, $locked];
    }
}
