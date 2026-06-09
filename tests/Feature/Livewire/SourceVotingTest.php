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
        ->assertDispatched('rating-voted', postId: $post->id, groupKey: 'source');

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
        ->dispatch('rating-voted', postId: $post->id, groupKey: 'source')
        ->assertOk();
});

it('renders source voting unavailable for a missing or unpublished post', function (int $postId) {
    Livewire::test(SourceVoting::class, ['postId' => $postId])
        ->assertSee('data-testid="source-voting-unavailable"', false)
        ->assertSee('Source voting unavailable');
})->with([
    'missing post' => fn () => 999999,
    'unpublished post' => fn () => Post::factory()->hidden()->create()->id,
]);

it('renders source voting unavailable when its rating configuration is missing', function () {
    RatingGroup::query()->where('key', 'source')->delete();
    $post = Post::factory()->published()->create();

    Livewire::test(SourceVoting::class, ['postId' => $post->id])
        ->assertSee('data-testid="source-voting-unavailable"', false);
});

it('scopes source option test ids to the post', function () {
    $post = Post::factory()->published()->create();
    $source = RatingGroup::query()->where('key', 'source')->firstOrFail();
    $option = $source->options()->active()->firstOrFail();

    Livewire::test(SourceVoting::class, ['postId' => $post->id])
        ->assertSee('data-testid="rating-option-'.$post->id.'-'.$option->id.'"', false);
});
