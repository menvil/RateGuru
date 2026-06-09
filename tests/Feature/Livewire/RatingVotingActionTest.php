<?php

use App\Livewire\Voting\RatingVoting;
use App\Models\Post;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\RatingVote;
use App\Models\User;
use Livewire\Livewire;

it('allows an authenticated user to vote through rating voting', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();
    $group = RatingGroup::factory()->create(['key' => 'source']);
    $option = RatingOption::factory()->for($group, 'group')->create();

    Livewire::actingAs($user)
        ->test(RatingVoting::class, ['post' => $post, 'groupKey' => 'source'])
        ->call('vote', $option->id)
        ->assertHasNoErrors()
        ->assertDispatched('rating-voted', postId: $post->id, groupKey: 'source')
        ->assertSee('data-testid="rating-option-'.$post->id.'-'.$option->id.'"', false)
        ->assertSee('text-rg-accent', false);

    $this->assertDatabaseHas('rating_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'rating_group_id' => $group->id,
        'rating_option_id' => $option->id,
    ]);
});

it('replaces a rating vote through the component without creating duplicates', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();
    $group = RatingGroup::factory()->create(['key' => 'source']);
    $first = RatingOption::factory()->for($group, 'group')->create();
    $second = RatingOption::factory()->for($group, 'group')->create();

    Livewire::actingAs($user)
        ->test(RatingVoting::class, ['post' => $post, 'groupKey' => 'source'])
        ->call('vote', $first->id)
        ->call('vote', $second->id)
        ->assertHasNoErrors();

    expect(RatingVote::query()
        ->where('user_id', $user->id)
        ->where('post_id', $post->id)
        ->where('rating_group_id', $group->id)
        ->count()
    )->toBe(1);

    $this->assertDatabaseHas('rating_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'rating_option_id' => $second->id,
    ]);
});

it('does not allow guests to vote through rating voting', function () {
    $post = Post::factory()->published()->create();
    $group = RatingGroup::factory()->create(['key' => 'source']);
    $option = RatingOption::factory()->for($group, 'group')->create();

    Livewire::test(RatingVoting::class, ['post' => $post, 'groupKey' => 'source'])
        ->call('vote', $option->id)
        ->assertSet('error', 'Guests cannot vote on rating options.')
        ->assertSee('Sign in to vote.');

    expect(RatingVote::query()->count())->toBe(0);
});

it('does not allow an option from a different rating group', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();
    RatingGroup::factory()->create(['key' => 'source']);
    $otherGroup = RatingGroup::factory()->create(['key' => 'category']);
    $otherOption = RatingOption::factory()->for($otherGroup, 'group')->create();

    Livewire::actingAs($user)
        ->test(RatingVoting::class, ['post' => $post, 'groupKey' => 'source'])
        ->call('vote', $otherOption->id)
        ->assertSee('Rating option is not available for this group.');

    expect(RatingVote::query()->count())->toBe(0);
});
