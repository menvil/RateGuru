<?php

use App\Actions\Categories\DeleteCategoryAction;
use App\Actions\Settings\SaveProjectSettingsAction;
use App\Actions\Settings\UpdateProjectLocaleSettingsAction;
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

/**
 * The repository's uniform lock order is Actor User first
 * (docs/architecture/user-lifecycle.md, and the LocksActorForWrite docblock).
 * This action took the category first, which inverts it against every other
 * write — and the opposing pair is real: CreatePostAction locks the actor and
 * then inserts a post, which takes a foreign-key lock on the category row it
 * references. Two transactions holding those rows in opposite orders deadlock.
 */
it('locks the actor before the category, the way every other write does', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $category = Category::factory()->create();

    /** @var list<array{table: string, locked: bool, sql: string}> $reads */
    $reads = [];

    DB::listen(function ($query) use (&$reads): void {
        // Identifier quoting is stripped rather than matched: every grammar
        // quotes differently (PostgreSQL and SQLite use "users", MySQL and
        // MariaDB use `users`), and a pattern that enumerates the styles it
        // knows about silently matches nothing on the engine it forgot — and
        // fails by observing nothing, which looks exactly like passing.
        $sql = str_replace(['"', '`'], '', $query->sql);

        foreach (['users', 'categories'] as $table) {
            if (preg_match('/\b(from|update|into)\s+'.$table.'\b/i', $sql) === 1) {
                $reads[] = [
                    'table' => $table,
                    'locked' => str_contains(strtolower($sql), 'for update'),
                    'sql' => $sql,
                ];
            }
        }
    });

    app(DeleteCategoryAction::class)->handle($admin, $category);

    $tables = array_column($reads, 'table');
    $report = implode(' -> ', array_map(
        fn (array $read): string => $read['table'].($read['locked'] ? ' (locked)' : ''),
        $reads,
    ));

    $firstUsers = array_search('users', $tables, true);
    $firstCategories = array_search('categories', $tables, true);

    expect($firstUsers)->not->toBeFalse('the actor row must be re-read at all')
        ->and($firstCategories)->not->toBeFalse('the category row must be read at all')
        ->and($firstUsers)->toBeLessThan($firstCategories, "the actor must be locked before the category: {$report}");

    // The order alone is not the contract — two unlocked reads in the right order
    // deadlock nothing and protect nothing. On an engine that has row locks, both
    // of these statements have to BE locks.
    //
    // SQLite is excluded deliberately rather than forgotten: its grammar compiles
    // lockForUpdate() to nothing at all, so there is no lock syntax to find and an
    // assertion about it would fail on a correct implementation.
    if (DB::connection()->getDriverName() !== 'sqlite') {
        expect($reads[$firstUsers]['locked'])
            ->toBeTrue("the actor row must be locked, not merely read: {$report}");
        expect($reads[$firstCategories]['locked'])
            ->toBeTrue("the category row must be locked, not merely read: {$report}");
    }
});

/**
 * The bootstrap used to exist twice, once in each settings writer. Two copies of
 * "what a new project starts from" can drift, and then a new project gets
 * different initial values depending on which admin page was opened first.
 */
it('bootstraps the settings row identically whichever writer creates it', function () {
    ProjectSettings::query()->delete();
    app(SaveProjectSettingsAction::class)->handle(['site_name' => 'Through settings']);
    $viaSettings = ProjectSettings::query()->findOrFail(1)->toArray();

    ProjectSettings::query()->delete();
    app(UpdateProjectLocaleSettingsAction::class)->handle(['en']);
    $viaLocales = ProjectSettings::query()->findOrFail(1)->toArray();

    // Each writer owns one column; everything else is the shared bootstrap and
    // must match exactly.
    $shared = fn (array $row): array => collect($row)
        ->except(['site_name', 'enabled_locales', 'created_at', 'updated_at'])
        ->all();

    expect($shared($viaLocales))->toBe($shared($viaSettings));
    expect(ProjectSettings::query()->count())->toBe(1);
});
