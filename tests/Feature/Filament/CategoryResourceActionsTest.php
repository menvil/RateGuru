<?php

use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use Livewire\Livewire;

it('deletes an unused category from category management', function () {
    $admin = User::factory()->admin()->create();
    $category = Category::factory()->create();
    $this->actingAs($admin);

    Livewire::test(ListCategories::class)
        ->callTableAction('delete', $category);

    $this->assertDatabaseMissing('categories', ['id' => $category->id]);
});

it('keeps a category that is assigned to posts', function () {
    $admin = User::factory()->admin()->create();
    $category = Category::factory()->create();
    Post::factory()->create(['category_id' => $category->id]);
    $this->actingAs($admin);

    Livewire::test(ListCategories::class)
        ->callTableAction('delete', $category);

    $this->assertDatabaseHas('categories', ['id' => $category->id]);
});
