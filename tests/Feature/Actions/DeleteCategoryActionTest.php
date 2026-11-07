<?php

use App\Actions\Categories\DeleteCategoryAction;
use App\Enums\StudioPlanStatus;
use App\Exceptions\Categories\CannotDeleteCategoryException;
use App\Models\Category;
use App\Models\Post;
use App\Models\StudioPlan;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

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

it('keeps a category an approved Content Studio plan will publish under, and lets a Draft\'s go', function (StudioPlanStatus $status, bool $kept) {
    $admin = User::factory()->admin()->create();
    $category = Category::factory()->create();
    $plan = StudioPlan::factory()->status($status)->create(['category_id' => $category->id]);

    $refused = null;

    try {
        app(DeleteCategoryAction::class)->handle($admin, $category);
    } catch (CannotDeleteCategoryException $exception) {
        $refused = $exception->reason;
    }

    expect($refused)->toBe($kept ? CannotDeleteCategoryException::REASON_AWAITED_BY_STUDIO_PLANS : null)
        ->and(Category::query()->whereKey($category->id)->exists())->toBe($kept)
        ->and($plan->fresh()->category_id)->toBe($kept ? $category->id : null);
})->with([
    'approved' => [StudioPlanStatus::Approved, true],
    'running' => [StudioPlanStatus::Running, true],
    'a draft' => [StudioPlanStatus::Draft, false],
    'completed' => [StudioPlanStatus::Completed, false],
    'cancelled' => [StudioPlanStatus::Cancelled, false],
]);

it('locks the category\'s plans, whatever their status, before the category, as the executor takes them', function () {
    $admin = User::factory()->admin()->create();
    $category = Category::factory()->create();
    StudioPlan::factory()->create(['category_id' => $category->id]);
    $locks = [];
    DB::listen(function (QueryExecuted $query) use (&$locks): void {
        if (str_contains(strtolower($query->sql), 'for update')) {
            $locks[] = str_contains($query->sql, 'studio_plans') ? 'plans' : (str_contains($query->sql, 'categories') ? 'category' : 'other');
        }
    });

    app(DeleteCategoryAction::class)->handle($admin, $category);

    // Not narrowed by status: a plan being approved still reads as a Draft.
    expect(array_values(array_intersect($locks, ['plans', 'category'])))->toBe(['plans', 'category']);
})->skip(fn (): bool => DB::getDriverName() === 'sqlite', 'SQLite takes no row locks.');
