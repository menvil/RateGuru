<?php

namespace App\Actions\Posts;

use App\Exceptions\Posts\CannotRestoreDeletedPostException;
use App\Models\Post;
use App\Models\User;
use App\Support\Cache\PostListCacheManager;
use Illuminate\Support\Facades\DB;

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
    public function __construct(
        private readonly PostListCacheManager $postListCache,
    ) {}

    public function handle(User $user, Post $post): void
    {
        DB::transaction(function () use ($user, $post): void {
            // The caller's instance may be stale (window expired meanwhile,
            // or a purge already ran); only the locked row decides.
            $locked = Post::withTrashed()
                ->whereKey($post->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw CannotRestoreDeletedPostException::becausePostIsNotAuthorDeleted();
            }

            if (! $user->canManageContent() || (int) $locked->user_id !== (int) $user->id) {
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
