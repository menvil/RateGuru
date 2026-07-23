<?php

use App\Models\Category;
use App\Queries\Categories\ActiveCategoriesQuery;

it('returns only active categories in deterministic display order', function () {
    $first = Category::factory()->create(['sort_order' => 5, 'is_active' => true]);
    $tied = Category::factory()->count(2)->create(['sort_order' => 10, 'is_active' => true]);
    Category::factory()->create(['sort_order' => 1, 'is_active' => false]);

    $categories = app(ActiveCategoriesQuery::class)->get();

    expect($categories->pluck('id')->all())
        ->toBe([$first->id, $tied[0]->id, $tied[1]->id]);
});
