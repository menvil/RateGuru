<?php

use App\Enums\VoteType;
use App\Livewire\Posts\PostVoting;
use App\Models\Post;
use App\Models\User;
use Livewire\Livewire;

it('refreshes vote counters after upvote', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create([
        'upvotes_count' => 0,
        'downvotes_count' => 0,
    ]);

    Livewire::actingAs($user)
        ->test(PostVoting::class, ['postId' => $post->id])
        ->assertSee('Up 0')
        ->call('vote', VoteType::Up->value)
        ->assertSee('Up 1');
});

it('refreshes vote counters after toggling upvote off', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create([
        'upvotes_count' => 0,
        'downvotes_count' => 0,
    ]);

    Livewire::actingAs($user)
        ->test(PostVoting::class, ['postId' => $post->id])
        ->call('vote', VoteType::Up->value)
        ->assertSee('Up 1')
        ->call('vote', VoteType::Up->value)
        ->assertSee('Up 0');
});

it('ignores an invalid vote type without throwing', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create([
        'upvotes_count' => 0,
        'downvotes_count' => 0,
    ]);

    Livewire::actingAs($user)
        ->test(PostVoting::class, ['postId' => $post->id])
        ->call('vote', 'sideways')
        ->assertOk();

    $this->assertDatabaseCount('post_votes', 0);
    expect($post->fresh()->upvotes_count)->toBe(0);
});

it('refreshes both counters when replacing a vote', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create([
        'upvotes_count' => 0,
        'downvotes_count' => 0,
    ]);

    Livewire::actingAs($user)
        ->test(PostVoting::class, ['postId' => $post->id])
        ->call('vote', VoteType::Up->value)
        ->assertSee('Up 1')
        ->assertSee('Down 0')
        ->call('vote', VoteType::Down->value)
        ->assertSee('Up 0')
        ->assertSee('Down 1');
});

it('has vote loading state markup', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(PostVoting::class, ['postId' => $post->id])
        ->assertSee('wire:loading', false)
        ->assertSee('wire:loading.attr="disabled"', false);
});

it('can render post voting component', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(PostVoting::class, ['postId' => $post->id])
        ->assertStatus(200)
        ->assertSee('Up')
        ->assertSee('Down');
});

it('calls vote action when up button is clicked', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(PostVoting::class, ['postId' => $post->id])
        ->call('vote', VoteType::Up->value)
        ->assertDispatched('post-voted');

    $this->assertDatabaseHas('post_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'type' => VoteType::Up->value,
    ]);
});

it('shows an error instead of throwing when the post is no longer available', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    $component = Livewire::actingAs($user)
        ->test(PostVoting::class, ['postId' => $post->id]);

    $post->update(['status' => \App\Enums\PostStatus::Hidden]);

    $component->call('vote', VoteType::Up->value)
        ->assertOk()
        ->assertSet('error', 'This post is no longer available.')
        ->assertNotDispatched('post-voted');

    $this->assertDatabaseCount('post_votes', 0);
});

it('calls vote action when down button is clicked', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(PostVoting::class, ['postId' => $post->id])
        ->call('vote', VoteType::Down->value)
        ->assertDispatched('post-voted');

    $this->assertDatabaseHas('post_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'type' => VoteType::Down->value,
    ]);
});
