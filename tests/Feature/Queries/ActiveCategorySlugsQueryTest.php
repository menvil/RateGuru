<?php

use App\Models\Category;
use App\Queries\Categories\ActiveCategorySlugsQuery;

it('returns only active category slugs in stable display order', function () {
    $first = Category::factory()->create(['slug' => 'first', 'sort_order' => 10]);
    $second = Category::factory()->create(['slug' => 'second', 'sort_order' => 10]);
    Category::factory()->inactive()->create(['slug' => 'inactive', 'sort_order' => 5]);

    expect(app(ActiveCategorySlugsQuery::class)->get())
        ->toBe([$first->slug, $second->slug]);
});
