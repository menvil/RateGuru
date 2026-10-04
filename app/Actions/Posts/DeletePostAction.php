<?php

namespace App\Actions\Posts;

use App\Enums\PostStatus;
use App\Exceptions\Posts\CannotDeletePostException;
use App\Models\Concerns\LocksActorForWrite;
use App\Models\Post;
use App\Models\User;
use App\Support\Cache\PostListCacheManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Author deletion — the only entry into post retention
 * (docs/architecture/post-lifecycle.md). Owner-only by policy: moderation
 * removes content via Hide/Restore and must never start the retention purge
 * clock. Soft-deletes with status Deleted and captures the source status so
 * RestoreDeletedPostAction can return the exact prior state.
 */
final class DeletePostAction
{
    use LocksActorForWrite;

    public function __construct(
        private readonly PostListCacheManager $postListCache,
    ) {}

    public function handle(User $user, Post $post): void
    {
        DB::transaction(function () use ($user, $post): void {
            // Lock order: Actor User -> Post. The caller's instances may be
            // stale; every decision below runs against rows re-read under
            // lock — including authorization, so a just-sanctioned owner
            // cannot finish an author deletion.
            $lockedActor = $this->lockActor($user);

            $locked = Post::withTrashed()
                ->whereKey($post->id)
                ->lockForUpdate()
                ->first();

            if ($lockedActor === null || $locked === null) {
                throw CannotDeletePostException::becauseUserIsNotAllowed();
            }

            if (! Gate::forUser($lockedActor)->allows('deleteFromFeed', $locked)) {
                throw CannotDeletePostException::becauseUserIsNotAllowed();
            }

            // Idempotent re-delete: no error, and crucially no deleted_at
            // refresh — a repeat must never extend the retention window.
            if ($locked->isAuthorDeleted()) {
                $post->setRawAttributes($locked->getAttributes(), true);

                return;
            }

            if ($locked->status === PostStatus::Hidden) {
                throw CannotDeletePostException::becausePostIsUnderModeration();
            }

            if ($locked->trashed() || ! in_array($locked->status, Post::AUTHOR_DELETABLE_STATUSES, true)) {
                throw CannotDeletePostException::becausePostStateIsInvalid();
            }

            $locked->forceFill([
                'deleted_from_status' => $locked->status,
                'status' => PostStatus::Deleted,
            ])->save();

            $locked->delete();

            $post->setRawAttributes($locked->getAttributes(), true);
        });

        $this->postListCache->invalidateForPost($post);
    }
}
