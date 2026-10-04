<?php

use App\Livewire\Voting\RatingVoting;
use App\Models\Post;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use Livewire\Livewire;

it('renders rating options container with mobile testid', function () {
    $post = Post::factory()->published()->create();

    $group = RatingGroup::factory()->create(['key' => 'attribute', 'is_active' => true]);
    RatingOption::factory()->count(4)->for($group, 'group')->create(['is_active' => true]);

    // testIdPrefix is "rating-option-{post->id}", so container is "rating-option-{id}-list"
    Livewire::test(RatingVoting::class, [
        'post' => $post,
        'groupKey' => 'attribute',
    ])
        ->assertSee('data-testid="rating-option-'.$post->id.'-list"', false);
});

it('renders ten rating options without losing options', function () {
    $post = Post::factory()->published()->create();

    $group = RatingGroup::factory()->create(['key' => 'attribute', 'is_active' => true]);
    RatingOption::factory()->count(10)->for($group, 'group')->create(['is_active' => true]);

    $component = Livewire::test(RatingVoting::class, [
        'post' => $post,
        'groupKey' => 'attribute',
    ]);

    $component->assertSee('data-testid="rating-option-'.$post->id.'-list"', false);

    // Match only individual option buttons (rating-option-{postId}-{optionId}),
    // not the list container (rating-option-{postId}-list)
    expect(preg_match_all('/data-testid="rating-option-\d+-\d+"/', $component->html()))->toBeGreaterThanOrEqual(10);
});

it('rating option buttons have mobile-safe tap target height', function () {
    $post = Post::factory()->published()->create();

    $group = RatingGroup::factory()->create(['key' => 'attribute', 'is_active' => true]);
    RatingOption::factory()->count(5)->for($group, 'group')->create(['is_active' => true]);

    $html = Livewire::test(RatingVoting::class, [
        'post' => $post,
        'groupKey' => 'attribute',
    ])->html();

    expect($html)->toContain('min-h-[40px]');
});
