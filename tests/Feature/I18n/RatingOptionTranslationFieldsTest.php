<?php

use App\Models\RatingGroup;
use App\Models\RatingOption;
use Illuminate\Support\Facades\Schema;

it('has rating option translation columns', function () {
    expect(Schema::hasColumns('rating_options', [
        'label_translations',
        'description_translations',
    ]))->toBeTrue();
});

it('casts rating option translations to arrays', function () {
    $group = RatingGroup::factory()->create();
    $option = RatingOption::factory()->create([
        'rating_group_id' => $group->id,
        'label_translations' => ['bg' => 'Опция А'],
    ]);

    expect($option->label_translations['bg'])->toBe('Опция А');
});

it('allows null rating option translations', function () {
    $group = RatingGroup::factory()->create();
    $option = RatingOption::factory()->create([
        'rating_group_id' => $group->id,
        'label_translations' => null,
    ]);

    expect($option->label_translations)->toBeNull();
});
