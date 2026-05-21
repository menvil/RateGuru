<?php

namespace App\Actions\Tags;

use App\Exceptions\Tags\CannotDeleteTagException;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DeleteTagAction
{
    /**
     * Delete an unused tag.
     *
     * Deleting taxonomy is irreversible and has long-term consequences,
     * so it is restricted to admins and blocked while any post still
     * references the tag. The pivot is never silently detached.
     */
    public function handle(User $admin, Tag $tag): void
    {
        if (! $admin->isAdmin()) {
            throw CannotDeleteTagException::becauseUserIsNotAllowed();
        }

        DB::transaction(function () use ($tag) {
            $locked = $tag->newQuery()->lockForUpdate()->find($tag->getKey());

            if ($locked === null) {
                return;
            }

            if ($locked->posts()->exists()) {
                throw CannotDeleteTagException::becauseTagIsUsedByPosts();
            }

            $locked->delete();
        });
    }
}
