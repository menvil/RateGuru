<?php

use App\Models\RatingGroup;
use Illuminate\Support\Facades\Schema;

it('has rating group translation columns', function () {
    expect(Schema::hasColumns('rating_groups', [
        'label_translations',
        'description_translations',
    ]))->toBeTrue();
});

it('casts rating group translations to arrays', function () {
    $group = RatingGroup::factory()->create([
        'label_translations' => ['ru' => 'Источник'],
    ]);

    expect($group->label_translations['ru'])->toBe('Источник');
});

it('allows null rating group translations', function () {
    $group = RatingGroup::factory()->create([
        'label_translations' => null,
    ]);

    expect($group->label_translations)->toBeNull();
});
