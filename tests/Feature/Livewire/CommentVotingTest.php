<?php

use App\Enums\VoteType;
use App\Livewire\Comments\CommentVoting;
use App\Models\Comment;
use App\Models\CommentVote;
use App\Models\User;
use Livewire\Livewire;

it('renders comment voting controls', function () {
    $comment = Comment::factory()->create();

    Livewire::test(CommentVoting::class, ['commentId' => $comment->id])
        ->assertStatus(200)
        ->assertSee('data-testid="comment-voting"', false)
        ->assertSee('Vote up')
        ->assertSee('Vote down');
});

it('records an upvote from the comment voting component', function () {
    $user = User::factory()->create();
    $comment = Comment::factory()->create(['upvotes_count' => 0, 'downvotes_count' => 0]);

    Livewire::actingAs($user)
        ->test(CommentVoting::class, ['commentId' => $comment->id])
        ->call('vote', VoteType::Up->value)
        ->assertDispatched('comment-voted')
        ->assertSee('1');

    $this->assertDatabaseHas('comment_votes', [
        'user_id' => $user->id,
        'comment_id' => $comment->id,
        'type' => VoteType::Up->value,
    ]);
});

it('keeps an active comment vote when clicked again in the UI', function () {
    $user = User::factory()->create();
    $comment = Comment::factory()->create(['upvotes_count' => 0, 'downvotes_count' => 0]);

    Livewire::actingAs($user)
        ->test(CommentVoting::class, ['commentId' => $comment->id])
        ->call('vote', VoteType::Up->value)
        ->assertSee('1')
        ->call('vote', VoteType::Up->value)
        ->assertSee('1');

    expect($comment->fresh())
        ->upvotes_count->toBe(1)
        ->downvotes_count->toBe(0);
});

it('stops comment vote clicks from triggering parent comment row actions', function () {
    $comment = Comment::factory()->create();

    Livewire::test(CommentVoting::class, ['commentId' => $comment->id])
        ->assertSee('x-on:click.stop', false)
        ->assertSee('x-on:keydown.stop', false)
        ->assertSee('wire:click.stop="vote(\'up\')"', false)
        ->assertSee('wire:click.stop="vote(\'down\')"', false);
});

it('renders selected comment voting state', function () {
    $user = User::factory()->create();
    $comment = Comment::factory()->create(['upvotes_count' => 1, 'downvotes_count' => 0]);

    CommentVote::factory()->create([
        'user_id' => $user->id,
        'comment_id' => $comment->id,
        'type' => VoteType::Up,
    ]);

    Livewire::actingAs($user)
        ->test(CommentVoting::class, ['commentId' => $comment->id])
        ->assertSee('aria-pressed="true"', false)
        ->assertSee('data-state="active"', false)
        ->assertSee('text-rg-good', false);
});
