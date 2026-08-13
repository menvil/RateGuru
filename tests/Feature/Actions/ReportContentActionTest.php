<?php

use App\Actions\Posts\DeletePostAction;
use App\Actions\Reports\ReportContentAction;
use App\Enums\CommentStatus;
use App\Enums\PostPurgeOutcome;
use App\Enums\PostStatus;
use App\Enums\ReportReason;
use App\Exceptions\Reports\CannotReportContentException;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Services\Posts\PostRetentionPurgeService;

it('allows user to report post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    $report = app(ReportContentAction::class)->handle(
        user: $user,
        content: $post,
        reason: ReportReason::Spam,
        message: 'This looks like spam.'
    );

    expect($report)->toBeInstanceOf(Report::class);
    expect($report->exists)->toBeTrue();
    expect($report->reporter_id)->toBe($user->id);
    expect($report->target_type)->toBe(Post::class);
    expect($report->target_id)->toBe($post->id);
    expect($report->reason)->toBe(ReportReason::Spam);
    expect($report->message)->toBe('This looks like spam.');
});

it('allows user to report comment', function () {
    $user = User::factory()->create();

    $comment = Comment::factory()->for(Post::factory()->published(), 'post')->create([
        'status' => CommentStatus::Visible,
    ]);

    $report = app(ReportContentAction::class)->handle(
        user: $user,
        content: $comment,
        reason: ReportReason::Offensive,
        message: 'This comment is abusive.'
    );

    expect($report)->toBeInstanceOf(Report::class);
    expect($report->target_type)->toBe(Comment::class);
    expect($report->target_id)->toBe($comment->id);
    expect($report->reason)->toBe(ReportReason::Offensive);
});

it('allows user to report another user', function () {
    $user = User::factory()->create();
    $target = User::factory()->create();

    $report = app(ReportContentAction::class)->handle(
        user: $user,
        content: $target,
        reason: ReportReason::Offensive,
        message: 'This user is abusive.'
    );

    expect($report)->toBeInstanceOf(Report::class);
    expect($report->target_type)->toBe(User::class);
    expect($report->target_id)->toBe($target->id);
    expect($report->reason)->toBe(ReportReason::Offensive);
    expect($report->message)->toBe('This user is abusive.');
    expect($report->reporter_id)->toBe($user->id);
});

it('does not allow guest to report content', function () {
    $post = Post::factory()->published()->create();

    try {
        app(ReportContentAction::class)->handle(
            user: null,
            content: $post,
            reason: ReportReason::Spam,
            message: null
        );
        $this->fail('Expected CannotReportContentException was not thrown.');
    } catch (CannotReportContentException $e) {
        // expected
    }

    expect(Report::query()->count())->toBe(0);
});

it('does not allow banned user to report content', function () {
    $user = User::factory()->banned()->create();
    $post = Post::factory()->published()->create();

    try {
        app(ReportContentAction::class)->handle(
            user: $user,
            content: $post,
            reason: ReportReason::Spam,
            message: null
        );
        $this->fail('Expected CannotReportContentException was not thrown.');
    } catch (CannotReportContentException $e) {
        // expected
    }

    expect(Report::query()->count())->toBe(0);
});

it('does not allow reporting a hidden comment', function () {
    $user = User::factory()->create();
    $comment = Comment::factory()->for(Post::factory()->published(), 'post')->create(['status' => CommentStatus::Hidden]);

    expect(fn () => app(ReportContentAction::class)->handle($user, $comment, ReportReason::Spam))
        ->toThrow(CannotReportContentException::class);

    expect(Report::query()->count())->toBe(0);
});

it('does not allow reporting an author-deleted comment', function () {
    $user = User::factory()->create();
    $comment = Comment::factory()->for(Post::factory()->published(), 'post')->create(['status' => CommentStatus::Visible]);
    $comment->delete();

    expect(fn () => app(ReportContentAction::class)->handle($user, $comment, ReportReason::Spam))
        ->toThrow(CannotReportContentException::class);

    expect(Report::query()->count())->toBe(0);
});

it('rejects a report made with a stale instance after the comment was hidden', function () {
    $user = User::factory()->create();
    $staleComment = Comment::factory()->for(Post::factory()->published(), 'post')->create([
        'status' => CommentStatus::Visible,
        'reports_count' => 0,
    ]);

    // Hide the row behind the instance's back: the in-memory model still
    // says Visible, so only an in-transaction re-read can catch this.
    Comment::query()->whereKey($staleComment->id)->update(['status' => CommentStatus::Hidden]);

    expect($staleComment->status)->toBe(CommentStatus::Visible);

    expect(fn () => app(ReportContentAction::class)->handle($user, $staleComment, ReportReason::Spam))
        ->toThrow(CannotReportContentException::class);

    expect(Report::query()->count())->toBe(0);
    expect($staleComment->fresh()->reports_count)->toBe(0);
});

it('rejects a report made with a stale instance after the author deleted the comment', function () {
    $user = User::factory()->create();
    $staleComment = Comment::factory()->for(Post::factory()->published(), 'post')->create([
        'status' => CommentStatus::Visible,
        'reports_count' => 0,
    ]);

    Comment::query()->whereKey($staleComment->id)->update(['deleted_at' => now()]);

    expect(fn () => app(ReportContentAction::class)->handle($user, $staleComment, ReportReason::Spam))
        ->toThrow(CannotReportContentException::class);

    expect(Report::query()->count())->toBe(0);
    expect(Comment::withTrashed()->findOrFail($staleComment->id)->reports_count)->toBe(0);
});

it('rejects reporting a still-Visible comment whose post was author-deleted', function () {
    // The comment row keeps its own Visible status in storage during post
    // retention, but the post is publicly gone — the comment must not
    // accept new reports.
    $owner = User::factory()->create();
    $post = Post::factory()->published()->for($owner)->create();
    $comment = Comment::factory()->create(['post_id' => $post->id, 'status' => CommentStatus::Visible]);

    app(DeletePostAction::class)->handle($owner, $post);

    $reporter = User::factory()->create();

    expect(fn () => app(ReportContentAction::class)->handle($reporter, $comment, ReportReason::Spam))
        ->toThrow(CannotReportContentException::class);

    expect(Report::query()->count())->toBe(0);
});

it('rejects reporting a Visible comment whose post is moderation-hidden', function () {
    $post = Post::factory()->hidden()->create();
    $comment = Comment::factory()->create(['post_id' => $post->id, 'status' => CommentStatus::Visible]);

    $reporter = User::factory()->create();

    expect(fn () => app(ReportContentAction::class)->handle($reporter, $comment, ReportReason::Spam))
        ->toThrow(CannotReportContentException::class);

    expect(Report::query()->count())->toBe(0);
});

it('rejects a comment report through a stale instance after the post graph was purged', function () {
    // Purge-vs-report ordering: the report path locks the parent post row
    // first, so it can never slip an open report in between the purge's
    // moderation-hold check and its comment sweep. If the purge wins, the
    // locked re-read finds the graph gone and the report is refused.
    config(['posts.author_delete_retention_days' => 0]);

    $owner = User::factory()->create();
    $post = Post::factory()->published()->for($owner)->create();
    $staleComment = Comment::factory()->create(['post_id' => $post->id, 'status' => CommentStatus::Visible]);

    app(DeletePostAction::class)->handle($owner, $post);

    expect(app(PostRetentionPurgeService::class)->purge($post->id))
        ->toBe(PostPurgeOutcome::Purged);

    $reporter = User::factory()->create();

    expect(fn () => app(ReportContentAction::class)->handle($reporter, $staleComment, ReportReason::Spam))
        ->toThrow(CannotReportContentException::class);

    expect(Report::query()->count())->toBe(0);
});

it('blocks duplicate report from same user for same post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(ReportContentAction::class)->handle($user, $post, ReportReason::Spam);

    try {
        app(ReportContentAction::class)->handle($user, $post, ReportReason::Spam);
        $this->fail('Expected CannotReportContentException was not thrown.');
    } catch (CannotReportContentException $e) {
        // expected
    }

    expect(Report::query()
        ->where('reporter_id', $user->id)
        ->where('target_type', Post::class)
        ->where('target_id', $post->id)
        ->count()
    )->toBe(1);
});

it('blocks duplicate report from same user for same comment', function () {
    $user = User::factory()->create();
    $comment = Comment::factory()->for(Post::factory()->published(), 'post')->create(['status' => CommentStatus::Visible]);

    app(ReportContentAction::class)->handle($user, $comment, ReportReason::Offensive);

    try {
        app(ReportContentAction::class)->handle($user, $comment, ReportReason::Offensive);
        $this->fail('Expected CannotReportContentException was not thrown.');
    } catch (CannotReportContentException $e) {
        // expected
    }

    expect(Report::query()
        ->where('reporter_id', $user->id)
        ->where('target_type', Comment::class)
        ->where('target_id', $comment->id)
        ->count()
    )->toBe(1);
});

it('allows same user to report different content items', function () {
    $user = User::factory()->create();
    $postA = Post::factory()->published()->create();
    $postB = Post::factory()->published()->create();

    app(ReportContentAction::class)->handle($user, $postA, ReportReason::Spam);
    app(ReportContentAction::class)->handle($user, $postB, ReportReason::Spam);

    expect(Report::query()->where('reporter_id', $user->id)->count())->toBe(2);
});

it('allows different users to report same content', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(ReportContentAction::class)->handle($userA, $post, ReportReason::Spam);
    app(ReportContentAction::class)->handle($userB, $post, ReportReason::Spam);

    expect(Report::query()
        ->where('target_type', Post::class)
        ->where('target_id', $post->id)
        ->count()
    )->toBe(2);
});

it('updates post reports count after report', function () {
    $user = User::factory()->create();

    $post = Post::factory()->published()->create([
        'reports_count' => 99,
    ]);

    app(ReportContentAction::class)->handle(
        user: $user,
        content: $post,
        reason: ReportReason::Spam
    );

    expect($post->fresh()->reports_count)->toBe(1);
});

it('rejects reporting a soft-deleted post and leaves its counters untouched', function () {
    // PR-E flipped the old contract: a deleted post is no longer publicly
    // reportable — existing reports remain, new ones are refused.
    $user = User::factory()->create();
    $post = Post::factory()->published()->create([
        'reports_count' => 99,
    ]);

    $post->delete();

    expect(fn () => app(ReportContentAction::class)->handle(
        user: $user,
        content: $post,
        reason: ReportReason::Spam,
    ))->toThrow(CannotReportContentException::class);

    expect(Post::withTrashed()->findOrFail($post->id)->reports_count)->toBe(99)
        ->and(Report::query()->count())->toBe(0);
});

it('updates comment reports count after report', function () {
    $user = User::factory()->create();

    $comment = Comment::factory()->for(Post::factory()->published(), 'post')->create([
        'reports_count' => 99,
        'status' => CommentStatus::Visible,
    ]);

    app(ReportContentAction::class)->handle(
        user: $user,
        content: $comment,
        reason: ReportReason::Offensive
    );

    expect($comment->fresh()->reports_count)->toBe(1);
});

it('flags post for review when report threshold is reached', function () {
    $post = Post::factory()->published()->create([
        'reports_count' => 0,
        'needs_review' => false,
        'flagged_at' => null,
    ]);

    $users = User::factory()->count(3)->create();

    foreach ($users as $user) {
        app(ReportContentAction::class)->handle(
            user: $user,
            content: $post->fresh(),
            reason: ReportReason::Spam
        );
    }

    $post->refresh();

    expect($post->reports_count)->toBe(3);
    expect($post->needs_review)->toBeTrue();
    expect($post->flagged_at)->not->toBeNull();
    expect($post->flagged_reason)->toBe('reports_threshold');
    expect($post->status)->toBe(PostStatus::Published);
});

it('does not reset flag metadata on reports after the threshold', function () {
    $post = Post::factory()->published()->create([
        'reports_count' => 0,
        'needs_review' => false,
        'flagged_at' => null,
    ]);

    $users = User::factory()->count(5)->create();

    foreach ($users->take(3) as $user) {
        app(ReportContentAction::class)->handle($user, $post->fresh(), ReportReason::Spam);
    }

    $flagged = $post->fresh();
    $flaggedAt = $flagged->flagged_at;
    $flaggedReason = $flagged->flagged_reason;

    foreach ($users->slice(3) as $user) {
        app(ReportContentAction::class)->handle($user, $post->fresh(), ReportReason::Spam);
    }

    $post->refresh();

    expect($post->reports_count)->toBe(5);
    expect($post->needs_review)->toBeTrue();
    expect($post->flagged_at->equalTo($flaggedAt))->toBeTrue();
    expect($post->flagged_reason)->toBe($flaggedReason);
});

it('does not flag post before report threshold is reached', function () {
    $post = Post::factory()->published()->create([
        'needs_review' => false,
    ]);

    $users = User::factory()->count(2)->create();

    foreach ($users as $user) {
        app(ReportContentAction::class)->handle($user, $post->fresh(), ReportReason::Spam);
    }

    expect($post->fresh()->needs_review)->toBeFalse();
});
