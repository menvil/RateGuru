<?php

namespace App\Actions\Categories;

use App\Models\Category;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class CreateCategoryAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $admin, array $data): Category
    {
        if (! $admin->can('create', Category::class)) {
            throw new AuthorizationException('User is not allowed to create categories.');
        }

        return Category::query()->create($data);
    }
}
