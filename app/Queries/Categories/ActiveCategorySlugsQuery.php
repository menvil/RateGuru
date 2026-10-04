<?php

namespace App\Queries\Categories;

use App\Models\Category;

final class ActiveCategorySlugsQuery
{
    /** @return list<string> */
    public function get(): array
    {
        return Category::query()
            ->active()
            ->ordered()
            ->pluck('slug')
            ->all();
    }
}
