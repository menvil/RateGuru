<?php

use App\Enums\PostStatus;
use App\Enums\VoteType;
use App\Livewire\Posts\PostVoting;
use App\Models\Post;
use App\Models\PostVote;
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

it('keeps upvote active when clicked again in the UI', function () {
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
        ->assertSee('Up 1');

    expect($post->fresh())
        ->upvotes_count->toBe(1)
        ->downvotes_count->toBe(0);
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

it('clears the current vote before applying the opposite vote in the UI', function () {
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

it('renders the compact rail personal vote correctly when replacing votes', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create([
        'upvotes_count' => 0,
        'downvotes_count' => 0,
    ]);

    Livewire::actingAs($user)
        ->test(PostVoting::class, ['postId' => $post->id, 'variant' => 'rail'])
        ->assertSee('post-voting-rail', false)
        ->assertSee('0')
        ->call('vote', VoteType::Up->value)
        ->assertSee('1')
        ->call('vote', VoteType::Down->value)
        ->assertSee('-1')
        ->call('vote', VoteType::Up->value)
        ->assertSee('1')
        ->call('vote', VoteType::Up->value)
        ->assertSee('1');

    expect($post->fresh())
        ->upvotes_count->toBe(1)
        ->downvotes_count->toBe(0);
});

it('stops rail vote clicks from bubbling to the feed card', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(PostVoting::class, ['postId' => $post->id, 'variant' => 'rail'])
        ->assertSee('x-on:click.stop', false)
        ->assertSee('x-on:keydown.stop', false)
        ->assertSee('wire:click.stop="vote(\'up\')"', false)
        ->assertSee('wire:click.stop="vote(\'down\')"', false);
});

it('refreshes matching post voting instances after another rail votes', function () {
    $post = Post::factory()->published()->create([
        'upvotes_count' => 1,
        'downvotes_count' => 0,
    ]);

    Livewire::test(PostVoting::class, ['postId' => $post->id, 'variant' => 'rail'])
        ->assertSee('1')
        ->dispatch('post-voted', postId: $post->id)
        ->assertSee('1');
});

it('renders distinct test ids for rail and pill variants', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(PostVoting::class, ['postId' => $post->id, 'variant' => 'rail'])
        ->assertSee('data-testid="post-voting-rail"', false);

    Livewire::test(PostVoting::class, ['postId' => $post->id, 'variant' => 'pill'])
        ->assertSee('data-testid="post-voting-pill"', false)
        ->assertDontSee('data-testid="post-voting-rail"', false);
});

it('has vote loading state markup', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(PostVoting::class, ['postId' => $post->id])
        ->assertSee('wire:loading', false)
        ->assertSee('wire:loading.attr="disabled"', false);
});

it('renders post voting buttons with accessible visual states', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    PostVote::factory()->create([
        'user_id' => $user->id,
        'post_id' => $post->id,
        'type' => VoteType::Up,
    ]);

    Livewire::actingAs($user)
        ->test(PostVoting::class, ['postId' => $post->id])
        ->assertSee('aria-pressed="true"', false)
        ->assertSee('data-state="active"', false)
        ->assertSee('border-rg-goodBorder', false)
        ->assertSee('focus-visible:ring-rg-accent', false);
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

it('shows vote rate limit error without mutating counters', function () {
    config()->set('rate_limits.vote.max_attempts', 1);
    config()->set('rate_limits.vote.decay_seconds', 60);

    $user = User::factory()->create();
    $first = Post::factory()->published()->create(['upvotes_count' => 0]);
    $second = Post::factory()->published()->create(['upvotes_count' => 0]);

    Livewire::actingAs($user)
        ->test(PostVoting::class, ['postId' => $first->id])
        ->call('vote', VoteType::Up->value)
        ->assertDispatched('post-voted');

    Livewire::actingAs($user)
        ->test(PostVoting::class, ['postId' => $second->id])
        ->call('vote', VoteType::Up->value)
        ->assertSet('error', 'You are voting too quickly. Please try again later.')
        ->assertNotDispatched('post-voted');

    expect($first->fresh()->upvotes_count)->toBe(1);
    expect($second->fresh()->upvotes_count)->toBe(0);
});

it('shows an error instead of throwing when the post is no longer available', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    $component = Livewire::actingAs($user)
        ->test(PostVoting::class, ['postId' => $post->id]);

    $post->update(['status' => PostStatus::Hidden]);

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
