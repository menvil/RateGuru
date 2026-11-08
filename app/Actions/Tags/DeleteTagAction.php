<?php

namespace App\Actions\Tags;

use App\Exceptions\Tags\CannotDeleteTagException;
use App\Models\Concerns\LocksActorForWrite;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DeleteTagAction
{
    use LocksActorForWrite;

    /**
     * Delete an unused tag.
     *
     * Deleting taxonomy is irreversible and has long-term consequences,
     * so it is restricted to admins and blocked while any post still
     * references the tag. The pivot is never silently detached.
     */
    public function handle(User $admin, Tag $tag): void
    {
        // Authorization (admin-only) lives in TagPolicy::delete. The
        // "tag is still used by posts" check below is a domain invariant
        // and stays here.
        if (! $admin->can('delete', $tag)) {
            throw CannotDeleteTagException::becauseUserIsNotAllowed();
        }

        DB::transaction(function () use ($admin, $tag) {
            // Lock order: Actor User -> Tag, as every write here takes them. The
            // actor is read again, as DeleteCategoryAction reads it: a demotion
            // or a ban landing after the check above would still have passed it.
            // Checked before the tag is looked for: one demoted meanwhile is
            // refused even when the tag is already gone, not told it was deleted.
            $actor = $this->lockActor($admin);

            if ($actor === null || ! $actor->can('delete', $tag)) {
                throw CannotDeleteTagException::becauseUserIsNotAllowed();
            }

            $locked = $tag->newQuery()->lockForUpdate()->find($tag->getKey());

            if ($locked === null) {
                return;
            }

            // With the posts their authors deleted: they can still be
            // restored, and deleting the tag would take it off them for good.
            if ($locked->posts()->withTrashed()->exists()) {
                throw CannotDeleteTagException::becauseTagIsUsedByPosts();
            }

            $locked->delete();
        });
    }
}
