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

        DB::transaction(function () use ($category): void {
            $locked = $category->newQuery()->lockForUpdate()->find($category->getKey());

            if ($locked === null) {
                return;
            }

            if ($locked->posts()->exists()) {
                throw CannotDeleteCategoryException::becauseCategoryIsUsedByPosts();
            }

            $locked->delete();
        });
    }
}
