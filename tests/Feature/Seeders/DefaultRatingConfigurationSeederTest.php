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
