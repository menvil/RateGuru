<?php

use App\Actions\Comments\AddCommentAction;
use App\Actions\Comments\DeleteCommentAction;
use App\Actions\Comments\HideCommentAction;
use App\Actions\Comments\RestoreCommentAction;
use App\Actions\Profile\AnonymizeUserAccountAction;
use App\Actions\Votes\VoteCommentAction;
use App\Enums\CommentStatus;
use App\Enums\ModerationActionType;
use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Enums\VoteType;
use App\Exceptions\Comments\CannotCommentException;
use App\Exceptions\Comments\CannotDeleteCommentException;
use App\Exceptions\Comments\CannotRestoreCommentException;
use App\Exceptions\Votes\CannotVoteCommentException;
use App\Models\Comment;
use App\Models\CommentVote;
use App\Models\ModerationLog;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Queries\Comments\CommentListQuery;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * PR-D contract: removing one comment must never destroy or silently hide
 * other users' surviving replies. Author deletion (SoftDeletes) and
 * moderation hide (status) stay orthogonal, distinguishable states.
 */

function threadFixture(): array
{
    $author = User::factory()->create();
    $post = Post::factory()->published()->create();
    $parent = Comment::factory()->for($author)->create([
        'post_id' => $post->id,
        'status' => CommentStatus::Visible,
        'body' => 'Original parent body',
    ]);
    $replyAuthor = User::factory()->create();
    $reply = Comment::factory()->for($replyAuthor)->create([
        'post_id' => $post->id,
        'parent_id' => $parent->id,
        'status' => CommentStatus::Visible,
        'body' => 'Surviving reply body',
    ]);

    return [$author, $post->fresh(), $parent, $reply];
}

// ---------------------------------------------------------------- deletion

it('lets the author delete a leaf comment: gone publicly, votes and reports kept', function () {
    $author = User::factory()->create();
    $post = Post::factory()->published()->create();
    $comment = Comment::factory()->for($author)->create(['post_id' => $post->id, 'status' => CommentStatus::Visible]);
    $voter = User::factory()->create();
    CommentVote::create(['user_id' => $voter->id, 'comment_id' => $comment->id, 'type' => VoteType::Up]);
    Report::create([
        'reporter_id' => $voter->id,
        'target_type' => Comment::class,
        'target_id' => $comment->id,
        'reason' => ReportReason::Spam,
        'status' => ReportStatus::Open,
    ]);

    app(DeleteCommentAction::class)->handle($author, $comment);

    expect(Comment::withTrashed()->find($comment->id)->trashed())->toBeTrue()
        ->and(app(CommentListQuery::class)->get($post->id, 'newest', 10))->toHaveCount(0)
        ->and($post->fresh()->comments_count)->toBe(0)
        ->and(CommentVote::query()->count())->toBe(1)
        ->and(Report::query()->count())->toBe(1);
});

it('refuses author deletion by anyone but the owner, including admins', function () {
    [, , $parent] = threadFixture();
    $admin = User::factory()->admin()->create();
    $stranger = User::factory()->create();

    expect(fn () => app(DeleteCommentAction::class)->handle($admin, $parent))
        ->toThrow(CannotDeleteCommentException::class);
    expect(fn () => app(DeleteCommentAction::class)->handle($stranger, $parent))
        ->toThrow(CannotDeleteCommentException::class);

    expect($parent->fresh()->trashed())->toBeFalse();
});

it('keeps surviving replies and counts them when the author deletes a parent', function () {
    [$author, $post, $parent, $reply] = threadFixture();
    $replyC = Comment::factory()->create([
        'post_id' => $post->id,
        'parent_id' => $parent->id,
        'status' => CommentStatus::Visible,
    ]);

    app(DeleteCommentAction::class)->handle($author, $parent);

    expect(Comment::withTrashed()->find($parent->id)->trashed())->toBeTrue()
        ->and($reply->fresh())->not->toBeNull()
        ->and($replyC->fresh())->not->toBeNull()
        ->and($post->fresh()->comments_count)->toBe(2);

    $roots = app(CommentListQuery::class)->get($post->id, 'newest', 10);
    expect($roots)->toHaveCount(1)
        ->and($roots->first()->id)->toBe($parent->id)
        ->and($roots->first()->isStructuralTombstone())->toBeTrue()
        ->and($roots->first()->replies)->toHaveCount(2);
});

it('is idempotent: double delete neither throws nor double-decrements', function () {
    [$author, $post, $parent] = threadFixture();

    app(DeleteCommentAction::class)->handle($author, $parent);
    $countAfterFirst = $post->fresh()->comments_count;

    app(DeleteCommentAction::class)->handle($author, Comment::withTrashed()->findOrFail($parent->id));

    expect($post->fresh()->comments_count)->toBe($countAfterFirst);
});

it('deletes a reply leaving the parent thread untouched', function () {
    [, $post, $parent, $reply] = threadFixture();
    $replyAuthor = $reply->user;

    app(DeleteCommentAction::class)->handle($replyAuthor, $reply);

    expect(Comment::withTrashed()->find($reply->id)->trashed())->toBeTrue()
        ->and($parent->fresh()->trashed())->toBeFalse()
        ->and($post->fresh()->comments_count)->toBe(1);

    $roots = app(CommentListQuery::class)->get($post->id, 'newest', 10);
    expect($roots)->toHaveCount(1)
        ->and($roots->first()->isStructuralTombstone())->toBeFalse()
        ->and($roots->first()->replies)->toHaveCount(0);
});

// ------------------------------------------------------------- moderation

it('hides a parent while keeping replies, then restores it in place', function () {
    [, $post, $parent, $reply] = threadFixture();
    $moderator = User::factory()->moderator()->create();

    app(HideCommentAction::class)->handle($moderator, $parent, 'Off the rails.');

    $fresh = $parent->fresh();
    expect($fresh->status)->toBe(CommentStatus::Hidden)
        ->and($fresh->trashed())->toBeFalse()
        ->and($fresh->isModeratorHidden())->toBeTrue()
        ->and($reply->fresh()->status)->toBe(CommentStatus::Visible)
        ->and($post->fresh()->comments_count)->toBe(1);

    $roots = app(CommentListQuery::class)->get($post->id, 'newest', 10);
    expect($roots)->toHaveCount(1)
        ->and($roots->first()->isStructuralTombstone())->toBeTrue();

    app(RestoreCommentAction::class)->handle($moderator, $parent->fresh());

    expect($parent->fresh()->status)->toBe(CommentStatus::Visible)
        ->and($parent->fresh()->body)->toBe('Original parent body')
        ->and($post->fresh()->comments_count)->toBe(2)
        ->and(ModerationLog::query()->pluck('action')->all())
        ->toBe([ModerationActionType::HideComment, ModerationActionType::RestoreComment]);
});

// -------------------------------------------------------------- races

it('lets the author delete an already hidden comment and blocks restore forever', function () {
    [$author, $post, $parent] = threadFixture();
    $moderator = User::factory()->moderator()->create();

    app(HideCommentAction::class)->handle($moderator, $parent);
    app(DeleteCommentAction::class)->handle($author, Comment::withTrashed()->findOrFail($parent->id));

    $final = Comment::withTrashed()->findOrFail($parent->id);
    expect($final->trashed())->toBeTrue()
        ->and($final->isAuthorDeleted())->toBeTrue();

    expect(fn () => app(RestoreCommentAction::class)->handle($moderator, $final))
        ->toThrow(CannotRestoreCommentException::class);

    expect(Comment::withTrashed()->findOrFail($parent->id)->trashed())->toBeTrue();
});

it('makes hide a silent no-op when the author already deleted the comment', function () {
    [$author, , $parent] = threadFixture();
    $moderator = User::factory()->moderator()->create();

    app(DeleteCommentAction::class)->handle($author, $parent);

    // The stale instance predates the deletion — the in-transaction re-read
    // must catch it and write no moderation state or log.
    app(HideCommentAction::class)->handle($moderator, $parent);

    $final = Comment::withTrashed()->findOrFail($parent->id);
    expect($final->status)->toBe(CommentStatus::Visible)
        ->and($final->trashed())->toBeTrue()
        ->and(ModerationLog::query()->count())->toBe(0);
});

it('rejects a reply whose parent was deleted after validation but before insert', function () {
    [$author, $post, $parent] = threadFixture();
    $replier = User::factory()->create();
    $staleParent = Comment::query()->findOrFail($parent->id);

    app(DeleteCommentAction::class)->handle($author, $parent);

    // The stale parent instance still looks valid; the in-transaction lock
    // and revalidation must reject the insert.
    expect(fn () => app(AddCommentAction::class)->handle($replier, $post, 'Too late', $staleParent))
        ->toThrow(CannotCommentException::class);

    expect(Comment::query()->where('parent_id', $parent->id)->where('body', 'Too late')->exists())->toBeFalse();
});

it('rejects a reply whose parent was hidden after validation but before insert', function () {
    [, $post, $parent] = threadFixture();
    $moderator = User::factory()->moderator()->create();
    $replier = User::factory()->create();
    $staleParent = Comment::query()->findOrFail($parent->id);

    app(HideCommentAction::class)->handle($moderator, $parent);

    expect(fn () => app(AddCommentAction::class)->handle($replier, $post, 'Too late', $staleParent))
        ->toThrow(CannotCommentException::class);
});

it('still rejects replying to a reply', function () {
    [, $post, , $reply] = threadFixture();
    $replier = User::factory()->create();

    expect(fn () => app(AddCommentAction::class)->handle($replier, $post, 'Nested', $reply))
        ->toThrow(CannotCommentException::class);
});

// -------------------------------------------------------------- votes

it('rejects new votes on hidden and author-deleted comments while keeping old votes', function () {
    [$author, , $parent, $reply] = threadFixture();
    $moderator = User::factory()->moderator()->create();
    $voter = User::factory()->create();
    CommentVote::create(['user_id' => $voter->id, 'comment_id' => $parent->id, 'type' => VoteType::Up]);

    app(HideCommentAction::class)->handle($moderator, $parent);

    expect(fn () => app(VoteCommentAction::class)->handle(User::factory()->create(), $parent->fresh(), VoteType::Up))
        ->toThrow(CannotVoteCommentException::class);

    app(DeleteCommentAction::class)->handle($reply->user, $reply);
    $trashedReply = Comment::withTrashed()->findOrFail($reply->id);

    expect($trashedReply->canReceiveVotes())->toBeFalse();
    expect(fn () => app(VoteCommentAction::class)->handle(User::factory()->create(), $trashedReply, VoteType::Up))
        ->toThrow(CannotVoteCommentException::class);

    expect(CommentVote::query()->count())->toBe(1);
});

// ------------------------------------------------- PR-B / PR-C regressions

it('keeps a Deleted User author distinct from a deleted comment', function () {
    [, $post, $parent, $reply] = threadFixture();
    $author = $parent->user;

    app(AnonymizeUserAccountAction::class)->execute($author);

    // Account tombstone: the comment body survives with a Deleted user
    // author label — the comment itself is NOT deleted.
    $roots = app(CommentListQuery::class)->get($post->id, 'newest', 10);
    expect($roots)->toHaveCount(1)
        ->and($roots->first()->isStructuralTombstone())->toBeFalse()
        ->and($roots->first()->body)->toBe('Original parent body')
        ->and($roots->first()->user->resolved_display_name)->toBe('Deleted user')
        ->and($roots->first()->replies)->toHaveCount(1)
        ->and($reply->fresh()->body)->toBe('Surviving reply body');
});

it('still hard-refuses physical deletion of a parent with replies or votes', function () {
    [, , $parent, $reply] = threadFixture();
    $voter = User::factory()->create();
    CommentVote::create(['user_id' => $voter->id, 'comment_id' => $reply->id, 'type' => VoteType::Up]);

    expect(fn () => DB::transaction(fn () => $parent->forceDelete()))
        ->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => $reply->forceDelete()))
        ->toThrow(QueryException::class);

    expect(Comment::query()->count())->toBe(2);
});
