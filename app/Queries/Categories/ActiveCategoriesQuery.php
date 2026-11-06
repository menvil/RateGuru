<?php

namespace App\Queries\Categories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Collection;

final class ActiveCategoriesQuery
{
    /** @return Collection<int, Category> */
    public function get(): Collection
    {
        return Category::query()
            ->active()
            ->ordered()
            ->get();
    }
}
