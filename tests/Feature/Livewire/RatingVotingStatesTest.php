<?php

use App\Livewire\Voting\RatingVoting;
use App\Models\Post;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\User;
use Livewire\Livewire;

it('renders rating options for guests without vote submission controls', function () {
    $post = Post::factory()->published()->create();
    $group = RatingGroup::factory()->create(['key' => 'source']);
    $option = RatingOption::factory()->for($group, 'group')->create();

    Livewire::test(RatingVoting::class, [
        'post' => $post,
        'groupKey' => 'source',
    ])
        ->assertSee($option->label)
        ->assertSee('Sign in to vote.')
        ->assertSee('disabled', false)
        ->assertDontSee('wire:click=', false);
});

it('renders unselected options for authenticated users without a vote', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();
    $group = RatingGroup::factory()->create(['key' => 'source']);
    $option = RatingOption::factory()->for($group, 'group')->create();

    Livewire::actingAs($user)
        ->test(RatingVoting::class, [
            'post' => $post,
            'groupKey' => 'source',
        ])
        ->assertSee('data-testid="rating-option-'.$option->id.'"', false)
        ->assertSee('aria-pressed="false"', false)
        ->assertDontSee('Sign in to vote.');
});

it('does not render inactive rating options', function () {
    $post = Post::factory()->published()->create();
    $group = RatingGroup::factory()->create(['key' => 'source']);
    $activeOption = RatingOption::factory()->for($group, 'group')->create(['is_active' => true]);
    $inactiveOption = RatingOption::factory()->for($group, 'group')->create(['is_active' => false]);

    Livewire::test(RatingVoting::class, [
        'post' => $post,
        'groupKey' => 'source',
    ])
        ->assertSee($activeOption->label)
        ->assertDontSee($inactiveOption->label);
});

it('does not render an inactive rating group', function () {
    $post = Post::factory()->published()->create();
    RatingGroup::factory()->create([
        'key' => 'source',
        'is_active' => false,
    ]);

    Livewire::test(RatingVoting::class, [
        'post' => $post,
        'groupKey' => 'source',
    ])->assertDontSee('data-testid="rating-voting-source"', false);
});
