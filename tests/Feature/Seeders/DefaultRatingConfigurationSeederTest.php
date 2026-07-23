<?php

use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DefaultRatingConfigurationSeeder;

it('seeds default rating groups and options', function () {
    $this->seed(DefaultRatingConfigurationSeeder::class);

    $type = RatingGroup::query()->where('key', 'type')->first();
    $attribute = RatingGroup::query()->where('key', 'attribute')->first();

    expect($type)->not->toBeNull()
        ->and($attribute)->not->toBeNull()
        ->and($type->options()->active()->count())->toBe(2)
        ->and($attribute->options()->active()->count())->toBe(3);
});

it('seeds default rating configuration idempotently', function () {
    $this->seed(DefaultRatingConfigurationSeeder::class);
    $this->seed(DefaultRatingConfigurationSeeder::class);

    expect(RatingGroup::query()->where('key', 'type')->count())->toBe(1)
        ->and(RatingGroup::query()->where('key', 'attribute')->count())->toBe(1)
        ->and(RatingOption::query()->count())->toBe(5);
});

it('uses generic default rating option keys', function () {
    $this->seed(DefaultRatingConfigurationSeeder::class);

    expect(RatingOption::query()->orderBy('key')->pluck('key')->all())->toBe([
        'attribute_a',
        'attribute_b',
        'attribute_c',
        'type_a',
        'type_b',
    ]);
});

it('includes default rating configuration in the database seeder', function () {
    $this->seed(DatabaseSeeder::class);

    expect(RatingGroup::query()->whereIn('key', ['type', 'attribute'])->count())
        ->toBe(2);
});

it('does not overwrite rating options from an installation preset', function () {
    ProjectSettings::factory()->create([
        'active_preset_key' => 'nature',
        'preset_applied_at' => now(),
    ]);
    $type = RatingGroup::factory()->create(['key' => 'photographer_type']);
    RatingOption::factory()->for($type, 'group')->create(['key' => 'professional']);

    $this->seed(DefaultRatingConfigurationSeeder::class);

    expect(RatingOption::query()->pluck('key')->all())->toBe(['professional']);
});

it('replaces only known active legacy default groups on an upgraded installation', function () {
    $source = RatingGroup::factory()->create(['key' => 'source']);
    RatingOption::factory()->for($source, 'group')->create(['key' => 'source_a']);
    RatingOption::factory()->for($source, 'group')->create(['key' => 'source_b']);

    $category = RatingGroup::factory()->create(['key' => 'category']);
    RatingOption::factory()->for($category, 'group')->create(['key' => 'category_a']);
    RatingOption::factory()->for($category, 'group')->create(['key' => 'category_b']);
    RatingOption::factory()->for($category, 'group')->create(['key' => 'category_c']);

    $unrelated = RatingGroup::factory()->create(['key' => 'confidence']);
    RatingOption::factory()->for($unrelated, 'group')->create(['key' => 'confidence_high']);

    $this->seed(DefaultRatingConfigurationSeeder::class);

    expect(RatingGroup::query()->active()->orderBy('key')->pluck('key')->all())
        ->toBe(['attribute', 'confidence', 'type'])
        ->and($source->fresh()->is_active)->toBeFalse()
        ->and($category->fresh()->is_active)->toBeFalse()
        ->and($unrelated->fresh()->is_active)->toBeTrue()
        ->and($source->options()->active()->exists())->toBeFalse()
        ->and($category->options()->active()->exists())->toBeFalse();
});
