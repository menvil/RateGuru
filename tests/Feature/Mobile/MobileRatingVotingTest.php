<?php

use App\Livewire\Voting\RatingVoting;
use App\Models\Post;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use Livewire\Livewire;

it('renders rating options container with mobile testid', function () {
    $post = Post::factory()->published()->create();

    $group = RatingGroup::factory()->create(['key' => 'category', 'is_active' => true]);
    RatingOption::factory()->count(4)->for($group, 'group')->create(['is_active' => true]);

    Livewire::test(RatingVoting::class, [
        'post' => $post,
        'groupKey' => 'category',
    ])
        ->assertSee('data-testid="rating-options"', false);
});

it('renders ten rating options without losing options', function () {
    $post = Post::factory()->published()->create();

    $group = RatingGroup::factory()->create(['key' => 'category', 'is_active' => true]);
    RatingOption::factory()->count(10)->for($group, 'group')->create(['is_active' => true]);

    $component = Livewire::test(RatingVoting::class, [
        'post' => $post,
        'groupKey' => 'category',
    ]);

    $component->assertSee('data-testid="rating-options"', false);

    expect(substr_count($component->html(), 'data-testid="rating-option'))->toBeGreaterThanOrEqual(10);
});

it('rating option buttons have mobile-safe tap target height', function () {
    $post = Post::factory()->published()->create();

    $group = RatingGroup::factory()->create(['key' => 'category', 'is_active' => true]);
    RatingOption::factory()->count(5)->for($group, 'group')->create(['is_active' => true]);

    $html = Livewire::test(RatingVoting::class, [
        'post' => $post,
        'groupKey' => 'category',
    ])->html();

    expect($html)->toContain('min-h-[40px]');
});
