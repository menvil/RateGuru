<?php

use App\Actions\Categories\DeleteCategoryAction;
use App\Exceptions\Categories\CannotDeleteCategoryException;
use App\Models\Category;
use App\Models\Post;
use App\Models\User;

it('deletes an unused category for an admin', function () {
    $admin = User::factory()->admin()->create();
    $category = Category::factory()->create();

    app(DeleteCategoryAction::class)->handle($admin, $category);

    $this->assertDatabaseMissing('categories', ['id' => $category->id]);
});

it('does not delete a category assigned to posts', function () {
    $admin = User::factory()->admin()->create();
    $category = Category::factory()->create();
    Post::factory()->create(['category_id' => $category->id]);

    try {
        app(DeleteCategoryAction::class)->handle($admin, $category);
        $this->fail('Expected CannotDeleteCategoryException.');
    } catch (CannotDeleteCategoryException $exception) {
        expect($exception->reason)->toBe(CannotDeleteCategoryException::REASON_USED_BY_POSTS);
    }

    $this->assertDatabaseHas('categories', ['id' => $category->id]);
});

it('does not allow a moderator to delete a category', function () {
    $moderator = User::factory()->moderator()->create();
    $category = Category::factory()->create();

    try {
        app(DeleteCategoryAction::class)->handle($moderator, $category);
        $this->fail('Expected CannotDeleteCategoryException.');
    } catch (CannotDeleteCategoryException $exception) {
        expect($exception->reason)->toBe(CannotDeleteCategoryException::REASON_NOT_ALLOWED);
    }

    $this->assertDatabaseHas('categories', ['id' => $category->id]);
});
