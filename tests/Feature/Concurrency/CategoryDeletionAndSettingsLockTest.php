<?php

use App\Actions\Categories\DeleteCategoryAction;
use App\Actions\Settings\SaveProjectSettingsAction;
use App\Enums\UserRole;
use App\Exceptions\Categories\CannotDeleteCategoryException;
use App\Models\Category;
use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * `posts.category_id` is `restrictOnDelete()` and `Post` soft-deletes, so a
 * retained author-deleted post still holds its category — invisible to the
 * default scope, and fatal to the DELETE. The guard checked the scoped relation,
 * so the operator met an uncaught database error instead of the explanation the
 * exception exists to give.
 */
it('refuses to delete a category still held by a soft-deleted post', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $category = Category::factory()->create();

    $post = Post::factory()->create(['category_id' => $category->id]);
    $post->delete();

    expect($post->fresh()->trashed())->toBeTrue();
    // The scoped relation cannot see it, which is exactly why the old check passed.
    expect($category->posts()->exists())->toBeFalse();

    expect(fn () => app(DeleteCategoryAction::class)->handle($admin, $category))
        ->toThrow(CannotDeleteCategoryException::class);

    expect(Category::query()->whereKey($category->id)->exists())->toBeTrue();
});

it('deletes a category nothing holds, trashed or otherwise', function () {
    // The other half: the stricter check must not refuse a legitimate deletion.
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $category = Category::factory()->create();

    app(DeleteCategoryAction::class)->handle($admin, $category);

    expect(Category::query()->whereKey($category->id)->exists())->toBeFalse();
});

it('re-authorizes the actor against the row, not the instance it was handed', function () {
    // The authorization check runs before the transaction, against whatever the
    // caller loaded. A demotion landing in between left the stale instance still
    // authorizing the deletion.
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $category = Category::factory()->create();

    $stale = User::query()->findOrFail($admin->id);

    // Demoted in the window — what account anonymization does.
    User::query()->whereKey($admin->id)->update(['role' => UserRole::User]);

    expect(fn () => app(DeleteCategoryAction::class)->handle($stale, $category))
        ->toThrow(CannotDeleteCategoryException::class);

    expect(Category::query()->whereKey($category->id)->exists())->toBeTrue();
});

/**
 * PostgreSQL locks no row that does not exist, so `lockForUpdate()->find(1)` on a
 * fresh installation returned null for every concurrent first save, and each then
 * inserted id 1 — one of them failing on the duplicate key.
 */
it('creates the settings row through firstOrCreate before locking it', function () {
    ProjectSettings::query()->delete();

    expect(ProjectSettings::query()->find(1))->toBeNull();

    app(SaveProjectSettingsAction::class)->handle(['site_name' => 'RateGuru']);

    expect(ProjectSettings::query()->findOrFail(1)->site_name)->toBe('RateGuru');

    // Idempotent: a second save finds the row and locks that, rather than racing
    // to create it again.
    app(SaveProjectSettingsAction::class)->handle(['site_name' => 'RateGuru Two']);

    expect(ProjectSettings::query()->count())->toBe(1);
    expect(ProjectSettings::query()->findOrFail(1)->site_name)->toBe('RateGuru Two');
});

/**
 * The sidebar cache was cleared on the model event, which inside a transaction
 * happens before the change is committed — leaving a window where a concurrent
 * render repopulates it from rows that are about to disappear.
 */
it('clears the sidebar cache only once the deletion has committed', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $category = Category::factory()->create();

    Cache::put('sidebar-nav-categories', ['stale'], now()->addMinutes(5));

    // Inside an outer transaction the forget must NOT have happened yet.
    DB::transaction(function () use ($admin, $category): void {
        app(DeleteCategoryAction::class)->handle($admin, $category);

        expect(Cache::get('sidebar-nav-categories'))->toBe(['stale']);
    });

    // And once committed, it is gone.
    expect(Cache::get('sidebar-nav-categories'))->toBeNull();
});
