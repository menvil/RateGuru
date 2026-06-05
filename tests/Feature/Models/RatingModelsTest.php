<?php

use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\RatingVote;

it('creates a rating group with options', function () {
    $group = RatingGroup::factory()
        ->has(RatingOption::factory()->count(2), 'options')
        ->create();

    expect($group->options)->toHaveCount(2);
});

it('creates a rating vote with matching relations', function () {
    $vote = RatingVote::factory()->create();

    expect($vote->post)->not->toBeNull()
        ->and($vote->user)->not->toBeNull()
        ->and($vote->group)->not->toBeNull()
        ->and($vote->option)->not->toBeNull()
        ->and($vote->option->group->is($vote->group))->toBeTrue();
});

it('exposes rating group vote relations', function () {
    $group = RatingGroup::factory()->create();
    $option = RatingOption::factory()->for($group, 'group')->create();
    $vote = RatingVote::factory()->for($group, 'group')->for($option, 'option')->create();

    expect($group->votes()->first()->is($vote))->toBeTrue()
        ->and($option->votes()->first()->is($vote))->toBeTrue();
});

it('filters active rating groups', function () {
    $active = RatingGroup::factory()->create(['is_active' => true]);
    RatingGroup::factory()->create(['is_active' => false]);

    expect(RatingGroup::active()->pluck('id')->all())->toBe([$active->id]);
});

it('filters and orders active rating options', function () {
    $group = RatingGroup::factory()->create();
    $second = RatingOption::factory()->for($group, 'group')->create([
        'is_active' => true,
        'sort_order' => 20,
    ]);
    $first = RatingOption::factory()->for($group, 'group')->create([
        'is_active' => true,
        'sort_order' => 10,
    ]);
    RatingOption::factory()->for($group, 'group')->create([
        'is_active' => false,
        'sort_order' => 0,
    ]);

    expect($group->options()->active()->ordered()->pluck('id')->all())
        ->toBe([$first->id, $second->id]);
});

it('casts rating configuration state', function () {
    $group = RatingGroup::factory()->create(['is_active' => true]);
    $option = RatingOption::factory()->for($group, 'group')->create([
        'is_active' => false,
        'archived_at' => now(),
    ]);

    expect($group->is_active)->toBeTrue()
        ->and($option->is_active)->toBeFalse()
        ->and($option->archived_at)->toBeInstanceOf(DateTimeInterface::class);
});
