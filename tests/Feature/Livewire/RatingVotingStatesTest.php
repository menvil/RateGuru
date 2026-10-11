<?php

use App\Livewire\Voting\RatingVoting;
use App\Models\Post;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\User;
use Livewire\Livewire;

it('renders rating options for guests without a proactive sign in message', function () {
    $post = Post::factory()->published()->create();
    $group = RatingGroup::factory()->create(['key' => 'type']);
    $option = RatingOption::factory()->for($group, 'group')->create();

    Livewire::test(RatingVoting::class, [
        'post' => $post,
        'groupKey' => 'type',
    ])
        ->assertSee($option->label)
        ->assertDontSee('Sign in to vote.')
        ->assertSee('wire:click="vote('.$option->id.')"', false);
});

it('renders unselected options for authenticated users without a vote', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();
    $group = RatingGroup::factory()->create(['key' => 'type']);
    $option = RatingOption::factory()->for($group, 'group')->create();

    Livewire::actingAs($user)
        ->test(RatingVoting::class, [
            'post' => $post,
            'groupKey' => 'type',
        ])
        ->assertSee('data-testid="rating-option-'.$post->id.'-'.$option->id.'"', false)
        ->assertSee('aria-pressed="false"', false)
        ->assertDontSee('Sign in to vote.');
});
