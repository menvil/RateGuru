<?php

use App\Actions\Comments\HideCommentAction;
use App\Actions\Comments\RestoreCommentAction;
use App\Actions\Moderation\ApprovePostAction;
use App\Actions\Moderation\HidePostAction;
use App\Actions\Moderation\RejectPostAction;
use App\Actions\Moderation\RestorePostAction;
use App\Actions\Reports\IgnoreReportAction;
use App\Actions\Reports\ResolveReportAction;
use App\Enums\CommentStatus;
use App\Enums\PostStatus;
use App\Enums\ReportStatus;
use App\Enums\UserStatus;
use App\Exceptions\Comments\CannotHideCommentException;
use App\Exceptions\Comments\CannotRestoreCommentException;
use App\Exceptions\Moderation\CannotModeratePostException;
use App\Exceptions\Reports\CannotIgnoreReportException;
use App\Exceptions\Reports\CannotResolveReportException;
use App\Models\Comment;
use App\Models\ModerationLog;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/*
 * PR-F blocker regression: content moderation is privileged participation.
 * A moderator sanctioned mid-request must not finish any moderation write —
 * the role-only policy check on the stale caller object is never enough;
 * every action re-reads the actor under lock inside its transaction.
 */

$staleActiveModerator = function (): User {
    $moderator = User::factory()->moderator()->create();

    User::query()->whereKey($moderator->id)->update(['status' => UserStatus::Banned]);

    expect($moderator->status)->toBe(UserStatus::Active);

    return $moderator;
};

it('denies content moderation to a sanctioned moderator at the policy layer', function (string $state) {
    $sanctioned = User::factory()->moderator()->{$state}()->create();
    $post = Post::factory()->published()->create();
    $comment = Comment::factory()->create(['post_id' => $post->id]);
    $report = Report::factory()->create(['target_type' => Post::class, 'target_id' => $post->id]);

    expect(Gate::forUser($sanctioned)->allows('moderate-content'))->toBeFalse()
        ->and($sanctioned->can('approve', $post))->toBeFalse()
        ->and($sanctioned->can('hide', $post))->toBeFalse()
        ->and($sanctioned->can('hide', $comment))->toBeFalse()
        ->and($sanctioned->can('resolve', $report))->toBeFalse()
        ->and($sanctioned->can('ignore', $report))->toBeFalse();
})->with(['limited', 'banned', 'shadowbanned']);

it('rejects a stale sanctioned moderator approving a post', function () use ($staleActiveModerator) {
    $stale = $staleActiveModerator();
    $post = Post::factory()->pending()->create();

    expect(fn () => app(ApprovePostAction::class)->handle($stale, $post))
        ->toThrow(CannotModeratePostException::class);

    expect($post->fresh()->status)->toBe(PostStatus::Pending)
        ->and(ModerationLog::query()->count())->toBe(0);
});

it('rejects a stale sanctioned moderator rejecting a post', function () use ($staleActiveModerator) {
    $stale = $staleActiveModerator();
    $post = Post::factory()->pending()->create();

    expect(fn () => app(RejectPostAction::class)->handle($stale, $post))
        ->toThrow(CannotModeratePostException::class);

    expect($post->fresh()->status)->toBe(PostStatus::Pending)
        ->and(ModerationLog::query()->count())->toBe(0);
});

it('rejects a stale sanctioned moderator hiding a post', function () use ($staleActiveModerator) {
    $stale = $staleActiveModerator();
    $post = Post::factory()->published()->create();

    expect(fn () => app(HidePostAction::class)->handle($stale, $post))
        ->toThrow(CannotModeratePostException::class);

    expect($post->fresh()->status)->toBe(PostStatus::Published)
        ->and(ModerationLog::query()->count())->toBe(0);
});

it('rejects a stale sanctioned moderator restoring a hidden post', function () use ($staleActiveModerator) {
    $stale = $staleActiveModerator();
    $post = Post::factory()->hidden()->create();

    expect(fn () => app(RestorePostAction::class)->handle($stale, $post))
        ->toThrow(CannotModeratePostException::class);

    expect($post->fresh()->status)->toBe(PostStatus::Hidden)
        ->and(ModerationLog::query()->count())->toBe(0);
});

it('rejects a stale sanctioned moderator hiding a comment', function () use ($staleActiveModerator) {
    $stale = $staleActiveModerator();
    $comment = Comment::factory()->for(Post::factory()->published(), 'post')->create();

    expect(fn () => app(HideCommentAction::class)->handle($stale, $comment))
        ->toThrow(CannotHideCommentException::class);

    expect($comment->fresh()->status)->toBe(CommentStatus::Visible)
        ->and(ModerationLog::query()->count())->toBe(0);
});

it('rejects a stale sanctioned moderator restoring a hidden comment', function () use ($staleActiveModerator) {
    $stale = $staleActiveModerator();
    $comment = Comment::factory()->for(Post::factory()->published(), 'post')->create(['status' => CommentStatus::Hidden]);

    expect(fn () => app(RestoreCommentAction::class)->handle($stale, $comment))
        ->toThrow(CannotRestoreCommentException::class);

    expect($comment->fresh()->status)->toBe(CommentStatus::Hidden)
        ->and(ModerationLog::query()->count())->toBe(0);
});

it('rejects a stale sanctioned moderator resolving a report', function () use ($staleActiveModerator) {
    $stale = $staleActiveModerator();
    $report = Report::factory()->create(['status' => ReportStatus::Open]);

    expect(fn () => app(ResolveReportAction::class)->handle($stale, $report, 'note'))
        ->toThrow(CannotResolveReportException::class);

    $fresh = $report->fresh();
    expect($fresh->status)->toBe(ReportStatus::Open)
        ->and($fresh->resolved_by)->toBeNull()
        ->and($fresh->resolved_at)->toBeNull()
        ->and($fresh->resolution_note)->toBeNull();
});

it('rejects a stale sanctioned moderator ignoring a report', function () use ($staleActiveModerator) {
    $stale = $staleActiveModerator();
    $report = Report::factory()->create(['status' => ReportStatus::Open]);

    expect(fn () => app(IgnoreReportAction::class)->handle($stale, $report))
        ->toThrow(CannotIgnoreReportException::class);

    $fresh = $report->fresh();
    expect($fresh->status)->toBe(ReportStatus::Open)
        ->and($fresh->resolved_by)->toBeNull();
});

it('still lets an active moderator moderate normally after the guards', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->pending()->create();

    app(ApprovePostAction::class)->handle($moderator, $post);

    expect($post->fresh()->status)->toBe(PostStatus::Published)
        ->and(ModerationLog::query()->count())->toBe(1);
});
