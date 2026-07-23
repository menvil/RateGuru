<?php

use App\Models\Category;
use App\Models\ProjectSettings;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DefaultCategorySeeder;

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

it('includes default categories in the database seeder', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Category::query()->active()->count())->toBe(3);
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
