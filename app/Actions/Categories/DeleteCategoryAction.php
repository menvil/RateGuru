<?php

namespace App\Actions\Categories;

use App\Exceptions\Categories\CannotDeleteCategoryException;
use App\Models\Category;
use App\Models\Concerns\LocksActorForWrite;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DeleteCategoryAction
{
    use LocksActorForWrite;

    public function handle(User $admin, Category $category): void
    {
        if (! $admin->can('delete', $category)) {
            throw CannotDeleteCategoryException::becauseUserIsNotAllowed();
        }

        DB::transaction(function () use ($admin, $category): void {
            // Lock order: Actor User -> Category, the repository's uniform order
            // (docs/architecture/user-lifecycle.md, and the LocksActorForWrite
            // docblock). Taking the category first inverted it against every
            // other write, and the opposing pair is real rather than theoretical:
            // CreatePostAction locks the actor and then inserts a post, which
            // takes a foreign-key lock on the category row it references. Two
            // transactions holding those two rows in opposite orders deadlock.
            //
            // Re-read rather than trusted: account anonymization demotes a user,
            // and the check above ran against whatever the caller's instance held
            // when it was loaded, so a demotion landing in between would still
            // have authorized this.
            $actor = $this->lockActor($admin);

            if ($actor === null) {
                throw CannotDeleteCategoryException::becauseUserIsNotAllowed();
            }

            $locked = $category->newQuery()->lockForUpdate()->find($category->getKey());

            if ($locked === null) {
                return;
            }

            if (! $actor->can('delete', $locked)) {
                throw CannotDeleteCategoryException::becauseUserIsNotAllowed();
            }

            // withTrashed, because the foreign key does not care about the scope.
            // `posts.category_id` is restrictOnDelete and Post soft-deletes, so a
            // retained author-deleted post still holds the category — invisible to
            // the default scope, and fatal to the DELETE below. Without this the
            // operator gets an uncaught database error instead of the explanation
            // this exception exists to give.
            if ($locked->posts()->withTrashed()->exists()) {
                throw CannotDeleteCategoryException::becauseCategoryIsUsedByPosts();
            }

            $locked->delete();
        });
    }
}
