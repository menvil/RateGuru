<?php

use App\Models\RatingGroup;
use App\Models\RatingOption;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DefaultRatingConfigurationSeeder;

it('seeds default rating groups and options', function () {
    $this->seed(DefaultRatingConfigurationSeeder::class);

    $source = RatingGroup::query()->where('key', 'source')->first();
    $category = RatingGroup::query()->where('key', 'category')->first();

    expect($source)->not->toBeNull()
        ->and($category)->not->toBeNull()
        ->and($source->options()->active()->count())->toBe(2)
        ->and($category->options()->active()->count())->toBe(3);
});

it('seeds default rating configuration idempotently', function () {
    $this->seed(DefaultRatingConfigurationSeeder::class);
    $this->seed(DefaultRatingConfigurationSeeder::class);

    expect(RatingGroup::query()->where('key', 'source')->count())->toBe(1)
        ->and(RatingGroup::query()->where('key', 'category')->count())->toBe(1)
        ->and(RatingOption::query()->count())->toBe(5);
});

it('uses generic default rating option keys', function () {
    $this->seed(DefaultRatingConfigurationSeeder::class);

    expect(RatingOption::query()->orderBy('key')->pluck('key')->all())->toBe([
        'category_a',
        'category_b',
        'category_c',
        'source_a',
        'source_b',
    ]);
});

it('includes default rating configuration in the database seeder', function () {
    $this->seed(DatabaseSeeder::class);

    expect(RatingGroup::query()->whereIn('key', ['source', 'category'])->count())
        ->toBe(2);
});
