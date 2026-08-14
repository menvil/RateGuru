<?php

use App\Actions\Moderation\FinalizeCommentRemovalAction;
use App\Actions\Moderation\FinalizePostRemovalAction;
use App\Enums\CommentStatus;
use App\Enums\ModerationPurgeOutcome;
use App\Enums\PostStatus;
use App\Enums\ReportStatus;
use App\Models\Comment;
use App\Models\CommentVote;
use App\Models\MediaAsset;
use App\Models\ModerationLog;
use App\Models\Post;
use App\Models\PostAuthorAnswer;
use App\Models\PostSave;
use App\Models\PostVote;
use App\Models\RatingOption;
use App\Models\RatingVote;
use App\Models\Report;
use App\Models\Tag;
use App\Models\User;
use App\Services\Media\MediaReferenceChecker;
use App\Services\Moderation\ModerationContentPurgeService;
use Illuminate\Support\Facades\Storage;

function finalizedHiddenPost(): Post
{
    $post = Post::factory()->hidden()->create();

    app(FinalizePostRemovalAction::class)->handle(
        User::factory()->admin()->create(),
        $post,
        'Finalized for tests.',
    );

    return $post->fresh();
}

function finalizedHiddenComment(): Comment
{
    $comment = Comment::factory()->for(Post::factory()->published(), 'post')->create(['status' => CommentStatus::Hidden]);

    app(FinalizeCommentRemovalAction::class)->handle(
        User::factory()->admin()->create(),
        $comment,
        'Finalized for tests.',
    );

    return Comment::withTrashed()->findOrFail($comment->id);
}

// ------------------------------------------------------- disabled default

it('reports retention disabled by default and never touches finalized content', function () {
    config(['content_lifecycle.moderation.content_retention_days' => null]);

    $post = finalizedHiddenPost();
    $comment = finalizedHiddenComment();

    $this->travel(365 * 5)->days();

    $service = app(ModerationContentPurgeService::class);

    expect($service->purgePost($post->id))->toBe(ModerationPurgeOutcome::RetentionDisabled)
        ->and($service->purgeComment($comment->id))->toBe(ModerationPurgeOutcome::RetentionDisabled)
        ->and(Post::query()->find($post->id))->not->toBeNull()
        ->and(Comment::withTrashed()->find($comment->id))->not->toBeNull();
});

// --------------------------------------------------- reversible Hidden safety

it('never treats reversible Hidden content as purge material, even years later with overrides', function () {
    $post = Post::factory()->hidden()->create();
    $comment = Comment::factory()->for(Post::factory()->published(), 'post')->create(['status' => CommentStatus::Hidden]);

    config(['content_lifecycle.moderation.content_retention_days' => 0]);
    $this->travel(365 * 5)->days();

    $service = app(ModerationContentPurgeService::class);

    // Explicit manual override cannot bypass the finalization requirement.
    expect($service->purgePost($post->id, olderThanDays: 0))->toBe(ModerationPurgeOutcome::InvalidState)
        ->and($service->purgeComment($comment->id, olderThanDays: 0))->toBe(ModerationPurgeOutcome::InvalidState);

    // And reversible rows are not even candidates.
    expect($service->postCandidates(0)->pluck('id'))->not->toContain($post->id)
        ->and($service->commentCandidates(0)->pluck('id'))->not->toContain($comment->id);

    expect($post->fresh()->moderation_removed_at)->toBeNull()
        ->and($comment->fresh()->moderation_removed_at)->toBeNull();
});

// ------------------------------------------------------- retention boundary

it('pins the enabled retention boundary on moderation_removed_at', function () {
    config(['content_lifecycle.moderation.content_retention_days' => 90]);

    $post = finalizedHiddenPost();
    $service = app(ModerationContentPurgeService::class);

    $this->travelTo($post->moderation_removed_at->copy()->addDays(90)->subSecond());
    expect($service->purgePost($post->id))->toBe(ModerationPurgeOutcome::NotExpired);

    $this->travelTo($post->moderation_removed_at->copy()->addDays(90));
    expect($service->purgePost($post->id))->toBe(ModerationPurgeOutcome::Purged)
        ->and(Post::withTrashed()->find($post->id))->toBeNull();
});

// ------------------------------------------------------------- rich graph

it('purges a finalized post with its entire graph, keeping tags, users and logs', function () {
    config(['content_lifecycle.moderation.content_retention_days' => 30]);

    $owner = User::factory()->create();
    $post = Post::factory()->published()->for($owner)->withImage()->create();

    $tag = Tag::factory()->create();
    $post->tags()->attach($tag);

    $root = Comment::factory()->create(['post_id' => $post->id]);
    $reply = Comment::factory()->create(['post_id' => $post->id, 'parent_id' => $root->id]);
    Comment::query()->whereKey($reply->id)->update(['deleted_at' => now()]);
    CommentVote::create(['comment_id' => $root->id, 'user_id' => User::factory()->create()->id, 'type' => 'up']);

    PostVote::create(['post_id' => $post->id, 'user_id' => User::factory()->create()->id, 'type' => 'up']);
    $option = RatingOption::factory()->create();
    RatingVote::factory()->create(['post_id' => $post->id, 'rating_option_id' => $option->id]);
    PostSave::create(['post_id' => $post->id, 'user_id' => User::factory()->create()->id]);
    $post->authorAnswers()->create(['rating_group_id' => $option->rating_group_id, 'rating_option_id' => $option->id]);
    Report::factory()->resolved()->create(['target_type' => Post::class, 'target_id' => $post->id]);
    Report::factory()->resolved()->create(['target_type' => Comment::class, 'target_id' => $root->id]);

    // Hide, then finalize.
    Post::query()->whereKey($post->id)->update(['status' => PostStatus::Hidden]);
    app(FinalizePostRemovalAction::class)->handle(User::factory()->admin()->create(), $post->fresh(), 'Finalized.');

    $assetId = $post->fresh()->image_asset_id;
    $this->travel(31)->days();

    expect(app(ModerationContentPurgeService::class)->purgePost($post->id))->toBe(ModerationPurgeOutcome::Purged);

    expect(Post::withTrashed()->find($post->id))->toBeNull()
        ->and(Comment::withTrashed()->where('post_id', $post->id)->count())->toBe(0)
        ->and(CommentVote::query()->count())->toBe(0)
        ->and(PostVote::query()->count())->toBe(0)
        ->and(RatingVote::query()->count())->toBe(0)
        ->and(PostSave::query()->count())->toBe(0)
        ->and(PostAuthorAnswer::query()->count())->toBe(0)
        ->and(Report::query()->count())->toBe(0)
        ->and(Tag::query()->find($tag->id))->not->toBeNull()
        ->and(User::query()->find($owner->id))->not->toBeNull()
        ->and(ModerationLog::query()->count())->toBeGreaterThanOrEqual(1)
        ->and(MediaAsset::withTrashed()->findOrFail($assetId)->trashed())->toBeTrue();
});

// ------------------------------------------------------------ shared media

it('keeps a shared media asset active and soft-deletes a final reference without touching files', function () {
    config(['content_lifecycle.moderation.content_retention_days' => 30]);
    Storage::fake('public');
    Storage::disk('public')->put('posts/mod-final.jpg', 'bytes');

    $shared = MediaAsset::factory()->postImage()->create();
    $sharedPost = Post::factory()->hidden()->create(['image_asset_id' => $shared->id]);
    Post::factory()->published()->create(['image_asset_id' => $shared->id]);

    $solo = MediaAsset::factory()->postImage()->create(['disk' => 'public', 'path' => 'posts/mod-final.jpg']);
    $soloPost = Post::factory()->hidden()->create(['image_asset_id' => $solo->id]);

    $admin = User::factory()->admin()->create();
    app(FinalizePostRemovalAction::class)->handle($admin, $sharedPost, 'Finalized.');
    app(FinalizePostRemovalAction::class)->handle($admin, $soloPost, 'Finalized.');

    $this->travel(31)->days();

    $service = app(ModerationContentPurgeService::class);
    expect($service->purgePost($sharedPost->id))->toBe(ModerationPurgeOutcome::Purged)
        ->and($service->purgePost($soloPost->id))->toBe(ModerationPurgeOutcome::Purged);

    expect(MediaAsset::withTrashed()->findOrFail($shared->id)->trashed())->toBeFalse()
        ->and(MediaAsset::withTrashed()->findOrFail($solo->id)->trashed())->toBeTrue()
        ->and(Storage::disk('public')->exists('posts/mod-final.jpg'))->toBeTrue();
});

// ----------------------------------------------------------------- holds

it('blocks the finalized post purge on active evidence and releases once processed', function (string $holdKind) {
    config(['content_lifecycle.moderation.content_retention_days' => 30]);

    $post = finalizedHiddenPost();
    $comment = Comment::factory()->create(['post_id' => $post->id, 'status' => CommentStatus::Visible]);

    match ($holdKind) {
        'needs_review' => Post::query()->whereKey($post->id)->update(['needs_review' => true]),
        'open post report' => Report::factory()->create([
            'target_type' => Post::class, 'target_id' => $post->id, 'status' => ReportStatus::Open,
        ]),
        'open comment report' => Report::factory()->create([
            'target_type' => Comment::class, 'target_id' => $comment->id, 'status' => ReportStatus::Open,
        ]),
    };

    $this->travel(31)->days();

    $service = app(ModerationContentPurgeService::class);

    expect($service->purgePost($post->id))->toBe(ModerationPurgeOutcome::ModerationHold)
        ->and(Post::query()->find($post->id))->not->toBeNull();

    Post::query()->whereKey($post->id)->update(['needs_review' => false]);
    Report::query()->where('status', ReportStatus::Open)->update([
        'status' => ReportStatus::Resolved,
        'resolved_by' => User::factory()->moderator()->create()->id,
        'resolved_at' => now(),
    ]);

    expect($service->purgePost($post->id))->toBe(ModerationPurgeOutcome::Purged);
})->with(['needs_review', 'open post report', 'open comment report']);

// ------------------------------------------------------- finalized comments

it('purges a finalized leaf comment but holds structural anchors and parent post states', function () {
    config(['content_lifecycle.moderation.content_retention_days' => 30]);

    $service = app(ModerationContentPurgeService::class);

    // Plain finalized leaf on a live post: purged after retention.
    $leaf = finalizedHiddenComment();
    $this->travel(31)->days();
    expect($service->purgeComment($leaf->id))->toBe(ModerationPurgeOutcome::Purged)
        ->and(Comment::withTrashed()->find($leaf->id))->toBeNull();
    $this->travelBack();

    // Finalized root with a child row: structural anchor, even expired.
    $root = Comment::factory()->for(Post::factory()->published(), 'post')->create(['status' => CommentStatus::Hidden]);
    Comment::factory()->create(['post_id' => $root->post_id, 'parent_id' => $root->id]);
    app(FinalizeCommentRemovalAction::class)->handle(User::factory()->admin()->create(), $root, 'Finalized.');
    $this->travel(31)->days();
    expect($service->purgeComment($root->id))->toBe(ModerationPurgeOutcome::StructuralAnchor);
    $this->travelBack();

    // Parent post hidden: post-level cleanup owns the graph.
    $onHiddenPost = finalizedHiddenComment();
    Post::query()->whereKey($onHiddenPost->post_id)->update(['status' => PostStatus::Hidden]);
    $this->travel(31)->days();
    expect($service->purgeComment($onHiddenPost->id))->toBe(ModerationPurgeOutcome::ParentPostHold);
    $this->travelBack();

    // Parent post author-deleted: same hold.
    $onTrashedPost = finalizedHiddenComment();
    Post::query()->whereKey($onTrashedPost->post_id)->update([
        'status' => PostStatus::Deleted, 'deleted_at' => now(),
    ]);
    $this->travel(31)->days();
    expect($service->purgeComment($onTrashedPost->id))->toBe(ModerationPurgeOutcome::ParentPostHold);
});

it('holds a finalized comment purge on an open report', function () {
    config(['content_lifecycle.moderation.content_retention_days' => 30]);

    $comment = finalizedHiddenComment();
    $report = Report::factory()->create([
        'target_type' => Comment::class,
        'target_id' => $comment->id,
        'status' => ReportStatus::Open,
    ]);

    $this->travel(31)->days();

    $service = app(ModerationContentPurgeService::class);

    expect($service->purgeComment($comment->id))->toBe(ModerationPurgeOutcome::ModerationHold);

    $report->update(['status' => ReportStatus::Ignored]);

    expect($service->purgeComment($comment->id))->toBe(ModerationPurgeOutcome::Purged);
});

// ------------------------------------------------- concurrency and rollback

it('is idempotent under concurrent purges: the loser sees already_gone', function () {
    config(['content_lifecycle.moderation.content_retention_days' => 0]);

    $post = finalizedHiddenPost();
    $service = app(ModerationContentPurgeService::class);

    expect($service->purgePost($post->id))->toBe(ModerationPurgeOutcome::Purged)
        ->and($service->purgePost($post->id))->toBe(ModerationPurgeOutcome::AlreadyGone);
});

it('rolls the whole graph back when the media release fails', function () {
    config(['content_lifecycle.moderation.content_retention_days' => 0]);

    $post = Post::factory()->hidden()->withImage()->create();
    Comment::factory()->create(['post_id' => $post->id]);
    app(FinalizePostRemovalAction::class)->handle(User::factory()->admin()->create(), $post->fresh(), 'Finalized.');

    $this->mock(MediaReferenceChecker::class)
        ->shouldReceive('referencedAssetIds')
        ->once()
        ->andThrow(new RuntimeException('boom'));

    expect(fn () => app(ModerationContentPurgeService::class)->purgePost($post->id))
        ->toThrow(RuntimeException::class);

    expect(Post::query()->find($post->id))->not->toBeNull()
        ->and(Comment::query()->where('post_id', $post->id)->count())->toBe(1)
        ->and(MediaAsset::withTrashed()->findOrFail($post->fresh()->image_asset_id)->trashed())->toBeFalse();
});

it('does not require or mutate author-deletion state for moderation purges', function () {
    config(['content_lifecycle.moderation.content_retention_days' => 0, 'posts.author_delete_retention_days' => 'foo']);

    // Broken AUTHOR retention config must not affect moderation purges:
    // the two eligibility policies are fully separate.
    $post = finalizedHiddenPost();

    expect(app(ModerationContentPurgeService::class)->purgePost($post->id))->toBe(ModerationPurgeOutcome::Purged);
});
