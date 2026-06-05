<?php

use App\Exceptions\Rating\InvalidRatingGroupConfigurationException;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Support\Rating\RatingConfigurationManager;

it('fails when a rating group has fewer than its minimum active options', function () {
    $group = RatingGroup::factory()->create([
        'min_options' => 2,
        'max_options' => 10,
    ]);
    RatingOption::factory()->for($group, 'group')->create(['is_active' => true]);

    app(RatingConfigurationManager::class)
        ->validateGroupHasAllowedOptionCount($group);
})->throws(InvalidRatingGroupConfigurationException::class);

it('fails when a rating group has more than its maximum active options', function () {
    $group = RatingGroup::factory()->create([
        'min_options' => 2,
        'max_options' => 10,
    ]);
    RatingOption::factory()->count(11)->for($group, 'group')->create(['is_active' => true]);

    app(RatingConfigurationManager::class)
        ->validateGroupHasAllowedOptionCount($group);
})->throws(InvalidRatingGroupConfigurationException::class);

it('accepts rating groups at the active option count boundaries', function (int $activeOptionCount) {
    $group = RatingGroup::factory()->create([
        'min_options' => 2,
        'max_options' => 10,
    ]);
    RatingOption::factory()->count($activeOptionCount)->for($group, 'group')->create([
        'is_active' => true,
    ]);

    app(RatingConfigurationManager::class)
        ->validateGroupHasAllowedOptionCount($group);

    expect(true)->toBeTrue();
})->with([2, 10]);

it('does not count inactive rating options', function () {
    $group = RatingGroup::factory()->create([
        'min_options' => 2,
        'max_options' => 2,
    ]);
    RatingOption::factory()->count(2)->for($group, 'group')->create(['is_active' => true]);
    RatingOption::factory()->count(5)->for($group, 'group')->create(['is_active' => false]);

    app(RatingConfigurationManager::class)
        ->validateGroupHasAllowedOptionCount($group);

    expect(true)->toBeTrue();
});
