<?php

namespace App\Actions\Categories;

use App\Models\Category;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class UpdateCategoryAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $admin, Category $category, array $data): Category
    {
        if (! $admin->can('update', $category)) {
            throw new AuthorizationException('User is not allowed to update categories.');
        }

        $category->update($data);

        return $category;
    }
}
