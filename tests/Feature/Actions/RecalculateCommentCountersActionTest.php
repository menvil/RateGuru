<?php

use App\Actions\Counters\RecalculateCommentCountersAction;
use App\Enums\VoteType;
use App\Models\Comment;
use App\Models\CommentVote;
use App\Models\User;

it('recalculates comment counters without persisting unrelated dirty attributes', function () {
    $comment = Comment::factory()->create([
        'body' => 'Original body',
        'upvotes_count' => 99,
        'downvotes_count' => 99,
    ]);

    CommentVote::factory()->create([
        'comment_id' => $comment->id,
        'user_id' => User::factory()->create()->id,
        'type' => VoteType::Up,
    ]);

    $comment->body = 'Dirty body should not save';

    $result = app(RecalculateCommentCountersAction::class)->handle($comment);

    expect($result)->toBe(['upvotes' => 1, 'downvotes' => 0])
        ->and($comment->fresh())
        ->body->toBe('Original body')
        ->upvotes_count->toBe(1)
        ->downvotes_count->toBe(0);
});
