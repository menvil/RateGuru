<?php

use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use Livewire\Livewire;

it('allows only admins to access category management', function () {
    $admin = User::factory()->admin()->create();
    $moderator = User::factory()->moderator()->create();

    $this->actingAs($admin)
        ->get(CategoryResource::getUrl('index'))
        ->assertOk();

    $this->actingAs($moderator)
        ->get(CategoryResource::getUrl('index'))
        ->assertForbidden();
});

it('creates a localized category with navigation settings', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Livewire::test(CreateCategory::class)
        ->fillForm([
            'name' => 'Desserts',
            'slug' => 'desserts',
            'name_translations' => [
                'en' => 'Desserts',
                'ru' => 'Десерты',
                'bg' => 'Десерти',
            ],
            'sort_order' => 20,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $category = Category::query()->where('slug', 'desserts')->firstOrFail();

    expect($category->name)->toBe('Desserts')
        ->and($category->name_translations['ru'])->toBe('Десерты')
        ->and($category->sort_order)->toBe(20)
        ->and($category->is_active)->toBeTrue();
});

it('auto generates a category slug without overwriting a manual value', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Livewire::test(CreateCategory::class)
        ->fillForm(['name' => 'Sweet Pastries'])
        ->assertFormSet(['slug' => 'sweet-pastries'])
        ->fillForm(['slug' => 'pastries'])
        ->fillForm(['name' => 'Sweet Baked Pastries'])
        ->assertFormSet(['slug' => 'pastries']);
});

it('validates category slug and name', function () {
    $admin = User::factory()->admin()->create();
    Category::factory()->create(['slug' => 'desserts']);
    $this->actingAs($admin);

    Livewire::test(CreateCategory::class)
        ->fillForm([
            'name' => '',
            'slug' => 'desserts',
            'sort_order' => -1,
        ])
        ->call('create')
        ->assertHasFormErrors([
            'name' => 'required',
            'slug' => 'unique',
            'sort_order' => 'min',
        ]);
});

it('edits category metadata and active state', function () {
    $admin = User::factory()->admin()->create();
    $category = Category::factory()->create();
    $this->actingAs($admin);

    Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
        ->fillForm([
            'name' => 'Updated category',
            'slug' => 'updated-category',
            'sort_order' => 40,
            'is_active' => false,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $category->refresh();

    expect($category->name)->toBe('Updated category')
        ->and($category->slug)->toBe('updated-category')
        ->and($category->sort_order)->toBe(40)
        ->and($category->is_active)->toBeFalse();
});

it('lists categories with post counts', function () {
    $admin = User::factory()->admin()->create();
    $category = Category::factory()->create();
    Post::factory()->count(2)->create(['category_id' => $category->id]);
    $this->actingAs($admin);

    Livewire::test(ListCategories::class)
        ->assertCanSeeTableRecords([$category])
        ->assertTableColumnStateSet('posts_count', 2, record: $category);
});
