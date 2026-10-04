<?php

use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Support\Rating\RatingConfigurationManager;
use Illuminate\Support\Facades\DB;

it('returns active rating groups with active options in sort order', function () {
    $secondGroup = RatingGroup::factory()->create([
        'is_active' => true,
        'sort_order' => 20,
    ]);
    $firstGroup = RatingGroup::factory()->create([
        'is_active' => true,
        'sort_order' => 10,
    ]);
    $inactiveGroup = RatingGroup::factory()->create([
        'is_active' => false,
        'sort_order' => 0,
    ]);

    $secondOption = RatingOption::factory()->for($firstGroup, 'group')->create([
        'is_active' => true,
        'sort_order' => 20,
    ]);
    $firstOption = RatingOption::factory()->for($firstGroup, 'group')->create([
        'is_active' => true,
        'sort_order' => 10,
    ]);
    RatingOption::factory()->for($firstGroup, 'group')->create([
        'is_active' => false,
        'sort_order' => 0,
    ]);
    RatingOption::factory()->for($secondGroup, 'group')->create(['is_active' => true]);
    RatingOption::factory()->for($inactiveGroup, 'group')->create(['is_active' => true]);

    $groups = app(RatingConfigurationManager::class)->activeGroups();

    expect($groups->pluck('id')->all())->toBe([$firstGroup->id, $secondGroup->id])
        ->and($groups->first()->options->pluck('id')->all())->toBe([$firstOption->id, $secondOption->id]);
});

it('returns an active rating group by key with active options', function () {
    $group = RatingGroup::factory()->create([
        'key' => 'type',
        'is_active' => true,
    ]);
    $option = RatingOption::factory()->for($group, 'group')->create(['is_active' => true]);
    RatingOption::factory()->for($group, 'group')->create(['is_active' => false]);

    $found = app(RatingConfigurationManager::class)->activeGroupByKey('type');

    expect($found?->is($group))->toBeTrue()
        ->and($found?->options->modelKeys())->toBe([$option->id]);
});

it('does not return inactive rating groups by key', function () {
    RatingGroup::factory()->create([
        'key' => 'type',
        'is_active' => false,
    ]);

    expect(app(RatingConfigurationManager::class)->activeGroupByKey('type'))->toBeNull();
});

it('eager loads options for active rating groups', function () {
    $group = RatingGroup::factory()->create(['is_active' => true]);
    RatingOption::factory()->count(2)->for($group, 'group')->create(['is_active' => true]);

    $groups = app(RatingConfigurationManager::class)->activeGroups();

    DB::enableQueryLog();
    $groups->each(fn (RatingGroup $ratingGroup) => $ratingGroup->options->count());

    expect(DB::getQueryLog())->toBeEmpty();
});

it('accepts a rating group with an allowed active option count', function () {
    $group = RatingGroup::factory()->create([
        'min_options' => 2,
        'max_options' => 10,
    ]);
    RatingOption::factory()->count(2)->for($group, 'group')->create(['is_active' => true]);

    app(RatingConfigurationManager::class)->validateGroupHasAllowedOptionCount($group);

    expect(true)->toBeTrue();
});
