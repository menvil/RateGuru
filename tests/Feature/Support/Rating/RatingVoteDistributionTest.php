<?php

use App\Models\Post;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\RatingVote;
use App\Support\Rating\RatingVoteDistribution;

it('calculates rating vote distribution for a post and group', function () {
    $post = Post::factory()->published()->create();
    $group = RatingGroup::factory()->create();
    $first = RatingOption::factory()->for($group, 'group')->create(['sort_order' => 10]);
    $second = RatingOption::factory()->for($group, 'group')->create(['sort_order' => 20]);

    RatingVote::factory()->count(3)->for($post)->for($group, 'group')->for($first, 'option')->create();
    RatingVote::factory()->for($post)->for($group, 'group')->for($second, 'option')->create();

    $distribution = app(RatingVoteDistribution::class)->forPostAndGroup($post, $group);

    expect($distribution[$first->id]['option']->is($first))->toBeTrue()
        ->and($distribution[$first->id]['count'])->toBe(3)
        ->and($distribution[$first->id]['percent'])->toBe(75.0)
        ->and($distribution[$first->id]['label'])->toBe('3 votes · 75%')
        ->and($distribution[$second->id]['count'])->toBe(1)
        ->and($distribution[$second->id]['percent'])->toBe(25.0)
        ->and($distribution[$second->id]['label'])->toBe('1 vote · 25%');
});

it('returns zero counts and percentages when a group has no votes', function () {
    $post = Post::factory()->published()->create();
    $group = RatingGroup::factory()->create();
    $option = RatingOption::factory()->for($group, 'group')->create();

    $distribution = app(RatingVoteDistribution::class)->forPostAndGroup($post, $group);

    expect($distribution[$option->id]['count'])->toBe(0)
        ->and($distribution[$option->id]['percent'])->toBe(0.0)
        ->and($distribution[$option->id]['label'])->toBe('0 votes · 0%');
});

it('preserves archived rating options in historical distribution', function () {
    $post = Post::factory()->published()->create();
    $group = RatingGroup::factory()->create();
    $archived = RatingOption::factory()->for($group, 'group')->create([
        'is_active' => false,
        'archived_at' => now(),
    ]);
    RatingVote::factory()->for($post)->for($group, 'group')->for($archived, 'option')->create();

    $distribution = app(RatingVoteDistribution::class)->forPostAndGroup($post, $group);

    expect($distribution)->toHaveKey($archived->id)
        ->and($distribution[$archived->id]['count'])->toBe(1)
        ->and($distribution[$archived->id]['percent'])->toBe(100.0);
});
