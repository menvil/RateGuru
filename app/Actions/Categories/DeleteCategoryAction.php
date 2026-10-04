<?php

namespace App\Actions\Categories;

use App\Exceptions\Categories\CannotDeleteCategoryException;
use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DeleteCategoryAction
{
    public function handle(User $admin, Category $category): void
    {
        if (! $admin->can('delete', $category)) {
            throw CannotDeleteCategoryException::becauseUserIsNotAllowed();
        }

        DB::transaction(function () use ($admin, $category): void {
            $locked = $category->newQuery()->lockForUpdate()->find($category->getKey());

            if ($locked === null) {
                return;
            }

            // Re-authorized against the row, not the instance the caller handed
            // in. Account anonymization demotes a user, and the check above ran
            // against whatever that instance held when it was loaded — so a
            // demotion landing in between would still have authorized this.
            $actor = $admin->newQuery()->lockForUpdate()->find($admin->getKey());

            if ($actor === null || ! $actor->can('delete', $locked)) {
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
