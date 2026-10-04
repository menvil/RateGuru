<?php

use App\Actions\Comments\DeleteCommentAction;
use App\Actions\Comments\HideCommentAction;
use App\Actions\Moderation\FinalizeCommentRemovalAction;
use App\Actions\Posts\DeletePostAction;
use App\Actions\Posts\RestoreDeletedPostAction;
use App\Enums\CommentPurgeOutcome;
use App\Enums\CommentStatus;
use App\Enums\ModerationActionType;
use App\Enums\PostStatus;
use App\Enums\ReportStatus;
use App\Models\Comment;
use App\Models\CommentVote;
use App\Models\ModerationLog;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Services\Comments\CommentPhysicalDeletionService;
use App\Services\Comments\CommentRetentionPurgeService;

beforeEach(function () {
    config(['content_lifecycle.comments.author_delete_retention_days' => 30]);
});

function authorDeletedLeaf(): Comment
{
    $author = User::factory()->create();
    $post = Post::factory()->published()->create();
    $comment = Comment::factory()->for($author)->create(['post_id' => $post->id]);

    app(DeleteCommentAction::class)->handle($author, $comment);

    return Comment::withTrashed()->findOrFail($comment->id);
}

it('purges a pure author-deleted leaf exactly at the cutoff, sweeping votes and closed reports', function () {
    $leaf = authorDeletedLeaf();
    CommentVote::create(['comment_id' => $leaf->id, 'user_id' => User::factory()->create()->id, 'type' => 'up']);
    Report::factory()->resolved()->create(['target_type' => Comment::class, 'target_id' => $leaf->id]);
    $log = ModerationLog::create([
        'moderator_id' => User::factory()->moderator()->create()->id,
        'action' => ModerationActionType::HideComment,
        'target_type' => Comment::class,
        'target_id' => $leaf->id,
        'reason' => 'history',
        'metadata' => [],
    ]);

    $service = app(CommentRetentionPurgeService::class);
    $countBefore = (int) $leaf->post->fresh()->comments_count;

    // Strictly before the cutoff: retained.
    $this->travelTo($leaf->deleted_at->copy()->addDays(30)->subSecond());
    expect($service->purge($leaf->id))->toBe(CommentPurgeOutcome::NotExpired);

    // At the exact cutoff: eligible.
    $this->travelTo($leaf->deleted_at->copy()->addDays(30));
    expect($service->purge($leaf->id))->toBe(CommentPurgeOutcome::Purged);

    expect(Comment::withTrashed()->find($leaf->id))->toBeNull()
        ->and(CommentVote::query()->count())->toBe(0)
        ->and(Report::query()->count())->toBe(0)
        ->and(ModerationLog::query()->find($log->id))->not->toBeNull()
        ->and(Post::query()->find($leaf->post_id))->not->toBeNull()
        // The public count already excluded the author-deleted row; the
        // physical purge must not decrement it again.
        ->and((int) $leaf->post->fresh()->comments_count)->toBe($countBefore);
});

it('holds an author-deleted root as a structural anchor while any child row exists', function () {
    $author = User::factory()->create();
    $post = Post::factory()->published()->create();
    $root = Comment::factory()->for($author)->create(['post_id' => $post->id]);
    $reply = Comment::factory()->create(['post_id' => $post->id, 'parent_id' => $root->id]);

    app(DeleteCommentAction::class)->handle($author, $root);

    $this->travel(31)->days();

    $service = app(CommentRetentionPurgeService::class);

    expect($service->purge($root->id))->toBe(CommentPurgeOutcome::StructuralAnchor);

    $retained = Comment::withTrashed()->findOrFail($root->id);
    expect($retained->isStructuralTombstone())->toBeTrue()
        ->and($reply->fresh()->trashed())->toBeFalse();

    // Once the child is independently gone, the root may become eligible.
    app(DeleteCommentAction::class)->handle($reply->user, $reply->fresh());
    $this->travel(31)->days();

    expect($service->purge($reply->id))->toBe(CommentPurgeOutcome::Purged)
        ->and($service->purge($root->id))->toBe(CommentPurgeOutcome::Purged);
});

it('never purges an author-deleted comment that is still Hidden moderation evidence', function () {
    $author = User::factory()->create();
    $moderator = User::factory()->moderator()->create();
    $comment = Comment::factory()->for($author)->for(Post::factory()->published(), 'post')->create();

    app(HideCommentAction::class)->handle($moderator, $comment);
    app(DeleteCommentAction::class)->handle($author, $comment->fresh());

    $this->travel(31)->days();

    expect(app(CommentRetentionPurgeService::class)->purge($comment->id))
        ->toBe(CommentPurgeOutcome::InvalidState);

    expect(Comment::withTrashed()->find($comment->id))->not->toBeNull();

    // Admin finalization moves it under moderation retention instead.
    app(FinalizeCommentRemovalAction::class)->handle(
        User::factory()->admin()->create(),
        Comment::withTrashed()->findOrFail($comment->id),
        'Evidence retained.',
    );

    expect(app(CommentRetentionPurgeService::class)->purge($comment->id))
        ->toBe(CommentPurgeOutcome::InvalidState);
});

it('pauses cleanup while the parent post is author-retained, preserving PR-E restore', function () {
    config(['posts.author_delete_retention_days' => 60]);

    $owner = User::factory()->create();
    $post = Post::factory()->published()->for($owner)->create();

    $commentAuthor = User::factory()->create();
    $comment = Comment::factory()->for($commentAuthor)->create(['post_id' => $post->id]);
    app(DeleteCommentAction::class)->handle($commentAuthor, $comment);

    app(DeletePostAction::class)->handle($owner, $post);

    // Comment retention (30d) long expired; post still recoverable (60d).
    $this->travel(45)->days();

    expect(app(CommentRetentionPurgeService::class)->purge($comment->id))
        ->toBe(CommentPurgeOutcome::PostRetentionHold);

    expect(Comment::withTrashed()->find($comment->id))->not->toBeNull();

    // Post restore recovers the exact untouched discussion graph.
    app(RestoreDeletedPostAction::class)->handle($owner, Post::withTrashed()->findOrFail($post->id));

    $retained = Comment::withTrashed()->findOrFail($comment->id);
    expect($retained->trashed())->toBeTrue()
        ->and($retained->status)->toBe(CommentStatus::Visible);
});

it('pauses cleanup while the parent post is moderation-hidden', function () {
    $leaf = authorDeletedLeaf();
    Post::query()->whereKey($leaf->post_id)->update(['status' => PostStatus::Hidden]);

    $this->travel(31)->days();

    expect(app(CommentRetentionPurgeService::class)->purge($leaf->id))
        ->toBe(CommentPurgeOutcome::PostModerationHold);
});

it('holds on an open report and purges once it is processed', function () {
    $leaf = authorDeletedLeaf();
    $report = Report::factory()->create([
        'target_type' => Comment::class,
        'target_id' => $leaf->id,
        'status' => ReportStatus::Open,
    ]);

    $this->travel(31)->days();

    $service = app(CommentRetentionPurgeService::class);

    expect($service->purge($leaf->id))->toBe(CommentPurgeOutcome::OpenReportHold);

    $report->update(['status' => ReportStatus::Ignored]);

    expect($service->purge($leaf->id))->toBe(CommentPurgeOutcome::Purged)
        ->and(Report::query()->count())->toBe(0);
});

it('reports already_gone for missing rows and mutates nothing on dry-run', function () {
    expect(app(CommentRetentionPurgeService::class)->purge(999999))->toBe(CommentPurgeOutcome::AlreadyGone);

    $leaf = authorDeletedLeaf();
    CommentVote::create(['comment_id' => $leaf->id, 'user_id' => User::factory()->create()->id, 'type' => 'up']);

    $this->travel(31)->days();

    expect(app(CommentRetentionPurgeService::class)->purge($leaf->id, dryRun: true))
        ->toBe(CommentPurgeOutcome::WouldPurge);

    expect(Comment::withTrashed()->find($leaf->id))->not->toBeNull()
        ->and(CommentVote::query()->count())->toBe(1);
});

it('refuses a live comment as invalid state', function () {
    $live = Comment::factory()->for(Post::factory()->published(), 'post')->create();

    $this->travel(31)->days();

    expect(app(CommentRetentionPurgeService::class)->purge($live->id))
        ->toBe(CommentPurgeOutcome::InvalidState);
});

it('rolls back everything when the physical deletion fails mid-flight', function () {
    $leaf = authorDeletedLeaf();
    CommentVote::create(['comment_id' => $leaf->id, 'user_id' => User::factory()->create()->id, 'type' => 'up']);

    $this->travel(31)->days();

    $failing = Mockery::mock(CommentPhysicalDeletionService::class);
    $failing->shouldReceive('deleteLeaf')->once()->andReturnUsing(function (Comment $comment): void {
        CommentVote::query()->where('comment_id', $comment->id)->delete();

        throw new RuntimeException('boom');
    });
    app()->instance(CommentPhysicalDeletionService::class, $failing);

    expect(fn () => app(CommentRetentionPurgeService::class)->purge($leaf->id))
        ->toThrow(RuntimeException::class);

    // Partial vote deletion rolled back with the transaction.
    expect(Comment::withTrashed()->find($leaf->id))->not->toBeNull()
        ->and(CommentVote::query()->count())->toBe(1);
});

it('prefilters candidates to eligible author-deleted rows in id order', function () {
    $eligibleOld = authorDeletedLeaf();
    $eligibleOlder = authorDeletedLeaf();
    Comment::withTrashed()->whereKey($eligibleOld->id)->update(['deleted_at' => now()->subDays(40)]);
    Comment::withTrashed()->whereKey($eligibleOlder->id)->update(['deleted_at' => now()->subDays(50)]);

    // Excluded shapes: live, hidden author-deleted, finalized, young.
    Comment::factory()->for(Post::factory()->published(), 'post')->create();

    $hidden = authorDeletedLeaf();
    Comment::withTrashed()->whereKey($hidden->id)->update(['status' => CommentStatus::Hidden, 'deleted_at' => now()->subDays(40)]);

    $finalized = authorDeletedLeaf();
    Comment::withTrashed()->whereKey($finalized->id)->update(['moderation_removed_at' => now(), 'deleted_at' => now()->subDays(40)]);

    $young = authorDeletedLeaf();

    $ids = app(CommentRetentionPurgeService::class)->candidates()->orderBy('id')->pluck('id');

    // Only the eligible expired rows, in the command's id order.
    expect($ids->all())->toBe([$eligibleOld->id, $eligibleOlder->id])
        ->and($ids)->not->toContain($hidden->id, $finalized->id, $young->id);
});

it('fails closed when comment retention config is invalid', function () {
    $leaf = authorDeletedLeaf();
    $this->travel(31)->days();

    config(['content_lifecycle.comments.author_delete_retention_days' => 'foo']);

    expect(fn () => app(CommentRetentionPurgeService::class)->purge($leaf->id))
        ->toThrow(InvalidArgumentException::class);

    expect(Comment::withTrashed()->find($leaf->id))->not->toBeNull();
});
