<?php

namespace App\Actions\Posts;

use App\Exceptions\Posts\CannotRestoreDeletedPostException;
use App\Models\Concerns\LocksActorForWrite;
use App\Models\Post;
use App\Models\User;
use App\Support\Cache\PostListCacheManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Author self-service restore of an author-deleted post, allowed strictly
 * before the retention cutoff (docs/architecture/post-lifecycle.md).
 * Deliberately separate from the moderation RestorePostAction: restoring is
 * not publication (no follower jobs, no approval notification, no moderation
 * log) and returns the exact pre-deletion status — child data (comments,
 * votes, saves, answers, media) was never touched, so it needs no restore.
 */
final class RestoreDeletedPostAction
{
    use LocksActorForWrite;

    public function __construct(
        private readonly PostListCacheManager $postListCache,
    ) {}

    public function handle(User $user, Post $post): void
    {
        DB::transaction(function () use ($user, $post): void {
            // Lock order: Actor User -> Post. The caller's instances may be
            // stale (window expired, purge already ran, or the owner was
            // just sanctioned); only the locked rows decide.
            $lockedActor = $this->lockActor($user);

            $locked = Post::withTrashed()
                ->whereKey($post->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw CannotRestoreDeletedPostException::becausePostIsNotAuthorDeleted();
            }

            if ($lockedActor === null || ! Gate::forUser($lockedActor)->allows('restoreDeleted', $locked)) {
                throw CannotRestoreDeletedPostException::becauseUserIsNotAllowed();
            }

            if (! $locked->trashed() || ! $locked->isAuthorDeleted()) {
                throw CannotRestoreDeletedPostException::becausePostIsNotAuthorDeleted();
            }

            if (! in_array($locked->deleted_from_status, Post::AUTHOR_DELETABLE_STATUSES, true)) {
                throw CannotRestoreDeletedPostException::becauseDeletionStateIsInvalid();
            }

            if (! $locked->isAuthorRestorable()) {
                throw CannotRestoreDeletedPostException::becauseRestoreWindowExpired();
            }

            $locked->forceFill([
                'status' => $locked->deleted_from_status,
                'deleted_from_status' => null,
            ]);

            $locked->restore();

            $post->setRawAttributes($locked->getAttributes(), true);
        });

        $this->postListCache->invalidateForPost($post);
    }
}
