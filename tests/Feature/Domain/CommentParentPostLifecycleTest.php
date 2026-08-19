<?php

use App\Actions\Comments\DeleteCommentAction;
use App\Actions\Comments\HideCommentAction;
use App\Actions\Comments\RestoreCommentAction;
use App\Actions\Moderation\HidePostAction;
use App\Actions\Posts\DeletePostAction;
use App\Actions\Votes\VoteCommentAction;
use App\Enums\CommentStatus;
use App\Enums\VoteType;
use App\Exceptions\Comments\CannotDeleteCommentException;
use App\Exceptions\Votes\CannotVoteCommentException;
use App\Models\Comment;
use App\Models\CommentVote;
use App\Models\Post;
use App\Models\User;

/*
 * Final lifecycle principle: a Visible Comment alone is not enough — its
 * parent Post must also be a live public surface. A comment beneath an
 * author-deleted or Hidden post accepts no votes and no author mutation
 * (PR-E restore recovers the exact untouched discussion graph).
 * Moderation deliberately keeps working under any parent state.
 */

it('rejects votes on a still-Visible comment beneath an author-deleted post', function () {
    $owner = User::factory()->create();
    $post = Post::factory()->published()->for($owner)->create();
    $staleComment = Comment::factory()->create(['post_id' => $post->id]);
    $countBefore = (int) $staleComment->fresh()->upvotes_count;

    app(DeletePostAction::class)->handle($owner, $post);

    $voter = User::factory()->create();

    expect(fn () => app(VoteCommentAction::class)->handle($voter, $staleComment, VoteType::Up))
        ->toThrow(CannotVoteCommentException::class);

    expect(CommentVote::query()->count())->toBe(0)
        ->and((int) $staleComment->fresh()->upvotes_count)->toBe($countBefore);
});

it('rejects votes on a still-Visible comment beneath a Hidden post', function () {
    $post = Post::factory()->published()->create();
    $staleComment = Comment::factory()->create(['post_id' => $post->id]);

    app(HidePostAction::class)->handle(User::factory()->moderator()->create(), $post);

    $voter = User::factory()->create();

    expect(fn () => app(VoteCommentAction::class)->handle($voter, $staleComment, VoteType::Up))
        ->toThrow(CannotVoteCommentException::class);

    expect(CommentVote::query()->count())->toBe(0);
});

it('still votes and toggles under a live Published parent', function () {
    $comment = Comment::factory()->for(Post::factory()->published(), 'post')->create();
    $voter = User::factory()->create();

    app(VoteCommentAction::class)->handle($voter, $comment, VoteType::Up);
    expect(CommentVote::query()->count())->toBe(1)
        ->and((int) $comment->fresh()->upvotes_count)->toBe(1);

    app(VoteCommentAction::class)->handle($voter, $comment->fresh(), VoteType::Up);
    expect(CommentVote::query()->count())->toBe(0)
        ->and((int) $comment->fresh()->upvotes_count)->toBe(0);
});

it('rejects author deletion of a comment beneath an author-deleted post', function () {
    $owner = User::factory()->create();
    $post = Post::factory()->published()->for($owner)->create();

    $author = User::factory()->create();
    $staleComment = Comment::factory()->for($author)->create(['post_id' => $post->id]);

    app(DeletePostAction::class)->handle($owner, $post);

    expect(fn () => app(DeleteCommentAction::class)->handle($author, $staleComment))
        ->toThrow(CannotDeleteCommentException::class);

    expect(Comment::withTrashed()->findOrFail($staleComment->id)->trashed())->toBeFalse();
});

it('rejects author deletion of a comment beneath a Hidden post', function () {
    $post = Post::factory()->published()->create();
    $author = User::factory()->create();
    $staleComment = Comment::factory()->for($author)->create(['post_id' => $post->id]);

    app(HidePostAction::class)->handle(User::factory()->moderator()->create(), $post);

    expect(fn () => app(DeleteCommentAction::class)->handle($author, $staleComment))
        ->toThrow(CannotDeleteCommentException::class);

    expect(Comment::withTrashed()->findOrFail($staleComment->id)->trashed())->toBeFalse();
});

it('lets the author delete a comment beneath a live post with an exact count', function () {
    $post = Post::factory()->published()->create();
    $author = User::factory()->create();
    $comment = Comment::factory()->for($author)->create(['post_id' => $post->id]);
    Comment::factory()->create(['post_id' => $post->id]);

    app(DeleteCommentAction::class)->handle($author, $comment);

    expect(Comment::withTrashed()->findOrFail($comment->id)->trashed())->toBeTrue()
        ->and((int) $post->fresh()->comments_count)->toBe(1);
});

it('lets moderation hide and restore under any parent state with exact counts', function () {
    $owner = User::factory()->create();
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->for($owner)->create();
    $comment = Comment::factory()->create(['post_id' => $post->id]);

    // Parent becomes author-deleted; moderation still operates on the
    // child discussion (no new product rule).
    app(DeletePostAction::class)->handle($owner, $post);

    app(HideCommentAction::class)->handle($moderator, $comment);
    expect($comment->fresh()->status)->toBe(CommentStatus::Hidden);

    app(RestoreCommentAction::class)->handle($moderator, $comment->fresh());
    expect($comment->fresh()->status)->toBe(CommentStatus::Visible);

    // Count recomputed against the locked parent row both times.
    expect((int) Post::withTrashed()->findOrFail($post->id)->comments_count)->toBe(1);
});
