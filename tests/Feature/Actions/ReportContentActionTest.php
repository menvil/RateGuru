<?php

use App\Actions\Reports\ReportContentAction;
use App\Enums\CommentStatus;
use App\Enums\ReportReason;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Report;
use App\Exceptions\Reports\CannotReportContentException;
use App\Models\User;

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

    $comment = Comment::factory()->create([
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
    $comment = Comment::factory()->create(['status' => CommentStatus::Visible]);

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
