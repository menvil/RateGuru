<?php

use App\Models\Category;
use App\Models\ProjectSettings;
use Database\Seeders\DefaultCategorySeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

it('seeds generic standalone categories', function () {
    $this->seed(DefaultCategorySeeder::class);

    expect(Category::query()->active()->ordered()->pluck('slug')->all())
        ->toBe(['general', 'showcase', 'other']);
});

it('seeds default categories idempotently', function () {
    $this->seed(DefaultCategorySeeder::class);
    $this->seed(DefaultCategorySeeder::class);

    expect(Category::query()->count())->toBe(3);
});

it('does not overwrite categories from an installation preset', function () {
    ProjectSettings::factory()->create([
        'active_preset_key' => 'nature',
        'preset_applied_at' => now(),
    ]);
    Category::factory()->create(['slug' => 'landscape']);

    $this->seed(DefaultCategorySeeder::class);

    expect(Category::query()->pluck('slug')->all())->toBe(['landscape']);
});

it('rolls back every default category when one category fails', function () {
    config()->set('project_presets.generic.categories', [
        [
            'slug' => 'transactional-category',
            'name' => ['en' => 'Transactional', 'ru' => 'Транзакционная', 'bg' => 'Транзакционна'],
            'sort_order' => 10,
        ],
        [
            'slug' => 'invalid-category',
            'name' => array_fill_keys(supportedLocales(), null),
            'sort_order' => 20,
        ],
    ]);

    expect(fn () => $this->seed(DefaultCategorySeeder::class))
        ->toThrow(QueryException::class);

    expect(Category::query()->where('slug', 'transactional-category')->exists())
        ->toBeFalse();
});

it('lets the sidebar list the seeded categories at once, with model events off as DatabaseSeeder has them', function () {
    Cache::put('sidebar-nav-categories', collect(), 300);

    Model::withoutEvents(fn () => $this->seed(DefaultCategorySeeder::class));

    expect(Cache::has('sidebar-nav-categories'))->toBeFalse();
});
