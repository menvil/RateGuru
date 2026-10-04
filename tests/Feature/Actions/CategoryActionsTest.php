<?php

use App\Actions\Categories\CreateCategoryAction;
use App\Actions\Categories\UpdateCategoryAction;
use App\Models\Category;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

it('authorizes category creation inside the action', function () {
    $user = User::factory()->create();

    expect(fn () => app(CreateCategoryAction::class)->handle($user, [
        'name' => 'Blocked category',
        'slug' => 'blocked-category',
    ]))->toThrow(AuthorizationException::class);

    expect(Category::query()->where('slug', 'blocked-category')->exists())->toBeFalse();
});

it('authorizes category updates inside the action', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['name' => 'Original']);

    expect(fn () => app(UpdateCategoryAction::class)->handle($user, $category, [
        'name' => 'Blocked update',
    ]))->toThrow(AuthorizationException::class);

    expect($category->fresh()->name)->toBe('Original');
});
