<?php

use App\Models\Category;
use App\Models\Post;

it('belongs to posts through the standalone category relationship', function () {
    $category = Category::factory()->create();
    $post = Post::factory()->create(['category_id' => $category->id]);

    expect($post->category->is($category))->toBeTrue()
        ->and($category->posts()->whereKey($post->id)->exists())->toBeTrue();
});

it('returns active categories in deterministic display order', function () {
    $second = Category::factory()->create(['sort_order' => 20]);
    $first = Category::factory()->create(['sort_order' => 10]);
    Category::factory()->inactive()->create(['sort_order' => 5]);

    expect(Category::query()->active()->ordered()->pluck('id')->all())
        ->toBe([$first->id, $second->id]);
});

it('resolves translated category names with a canonical fallback', function () {
    $category = Category::factory()->create([
        'name' => 'Desserts',
        'name_translations' => [
            'en' => 'Desserts',
            'ru' => 'Десерты',
            'bg' => null,
        ],
    ]);

    expect($category->translatedName('ru'))->toBe('Десерты')
        ->and($category->translatedName('bg'))->toBe('Desserts');
});
