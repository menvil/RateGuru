<?php

use App\Actions\Votes\VoteCommentAction;
use App\Enums\CommentStatus;
use App\Enums\VoteType;
use App\Exceptions\Votes\CannotVoteCommentException;
use App\Models\Comment;
use App\Models\User;

it('records an upvote on a comment', function () {
    $user = User::factory()->create();
    $comment = Comment::factory()->create(['upvotes_count' => 0, 'downvotes_count' => 0]);

    app(VoteCommentAction::class)->handle($user, $comment, VoteType::Up);

    $this->assertDatabaseHas('comment_votes', [
        'user_id' => $user->id,
        'comment_id' => $comment->id,
        'type' => VoteType::Up->value,
    ]);

    expect($comment->fresh())
        ->upvotes_count->toBe(1)
        ->downvotes_count->toBe(0);
});

it('switches an existing comment vote', function () {
    $user = User::factory()->create();
    $comment = Comment::factory()->create(['upvotes_count' => 0, 'downvotes_count' => 0]);

    app(VoteCommentAction::class)->handle($user, $comment, VoteType::Up);
    app(VoteCommentAction::class)->handle($user, $comment->fresh(), VoteType::Down);

    $this->assertDatabaseHas('comment_votes', [
        'user_id' => $user->id,
        'comment_id' => $comment->id,
        'type' => VoteType::Down->value,
    ]);

    expect($comment->fresh())
        ->upvotes_count->toBe(0)
        ->downvotes_count->toBe(1);
});

it('removes a matching comment vote', function () {
    $user = User::factory()->create();
    $comment = Comment::factory()->create(['upvotes_count' => 0, 'downvotes_count' => 0]);

    app(VoteCommentAction::class)->handle($user, $comment, VoteType::Up);
    app(VoteCommentAction::class)->handle($user, $comment->fresh(), VoteType::Up);

    $this->assertDatabaseMissing('comment_votes', [
        'user_id' => $user->id,
        'comment_id' => $comment->id,
    ]);

    expect($comment->fresh())
        ->upvotes_count->toBe(0)
        ->downvotes_count->toBe(0);
});

it('does not let guests vote on comments', function () {
    $comment = Comment::factory()->create();

    expect(fn () => app(VoteCommentAction::class)->handle(null, $comment, VoteType::Up))
        ->toThrow(CannotVoteCommentException::class, 'Guests cannot vote on comments.');
});

it('does not let users vote on their own comments', function () {
    $user = User::factory()->create();
    $comment = Comment::factory()->for($user)->create();

    expect(fn () => app(VoteCommentAction::class)->handle($user, $comment, VoteType::Up))
        ->toThrow(CannotVoteCommentException::class, 'You cannot vote on your own comment.');
});

it('does not let users vote on hidden comments', function () {
    $user = User::factory()->create();
    $comment = Comment::factory()->create(['status' => CommentStatus::Hidden]);

    expect(fn () => app(VoteCommentAction::class)->handle($user, $comment, VoteType::Up))
        ->toThrow(CannotVoteCommentException::class, 'Comment cannot be voted on.');
});
