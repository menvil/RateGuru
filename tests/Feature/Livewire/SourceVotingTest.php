<?php

use App\Livewire\Posts\SourceVoting;
use App\Models\Post;
use App\Models\RatingGroup;
use App\Models\User;
use Database\Seeders\DefaultRatingConfigurationSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(DefaultRatingConfigurationSeeder::class);
});

it('renders source voting through generic rating configuration', function () {
    $post = Post::factory()->published()->create();
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(SourceVoting::class, ['postId' => $post->id])
        ->assertSee('data-testid="source-voting"', false)
        ->assertSee('Source')
        ->assertSee('Source A')
        ->assertSee('Source B');
});

it('stores source votes in the generic rating votes table', function () {
    $post = Post::factory()->published()->create();
    $user = User::factory()->create();
    $source = RatingGroup::query()->where('key', 'source')->firstOrFail();
    $option = $source->options()->active()->firstOrFail();

    Livewire::actingAs($user)
        ->test(SourceVoting::class, ['postId' => $post->id])
        ->call('vote', $option->id)
        ->assertDispatched('source-voted');

    $this->assertDatabaseHas('rating_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'rating_group_id' => $source->id,
        'rating_option_id' => $option->id,
    ]);

    $this->assertDatabaseMissing('origin_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
    ]);
});

it('refreshes matching source voting instances', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(SourceVoting::class, ['postId' => $post->id])
        ->dispatch('source-voted', postId: $post->id)
        ->assertOk();
});
