<?php

use App\Actions\Posts\DeletePostAction;
use App\Enums\ModerationActionType;
use App\Enums\PostPurgeOutcome;
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
use App\Services\Media\MediaLifecycleService;
use App\Services\Media\MediaReferenceChecker;
use App\Services\Posts\PostRetentionPurgeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['posts.author_delete_retention_days' => 30]);
});

/**
 * Author-deleted Published post with the full dependency graph:
 * image asset, root comment + reply (with votes and a resolved report),
 * post votes, rating vote, save, author answer, tag, resolved post report.
 */
function purgeableGraph(): array
{
    $owner = User::factory()->create();
    $post = Post::factory()->published()->for($owner)->withImage()->create();

    $tag = Tag::factory()->create();
    $post->tags()->attach($tag);

    $root = Comment::factory()->create(['post_id' => $post->id]);
    $reply = Comment::factory()->create(['post_id' => $post->id, 'parent_id' => $root->id]);
    CommentVote::create(['comment_id' => $root->id, 'user_id' => User::factory()->create()->id, 'type' => 'up']);

    PostVote::create(['post_id' => $post->id, 'user_id' => User::factory()->create()->id, 'type' => 'up']);

    $option = RatingOption::factory()->create();
    RatingVote::factory()->create(['post_id' => $post->id, 'rating_option_id' => $option->id]);

    PostSave::create(['post_id' => $post->id, 'user_id' => User::factory()->create()->id]);

    $post->authorAnswers()->create([
        'rating_group_id' => $option->rating_group_id,
        'rating_option_id' => $option->id,
    ]);

    Report::factory()->resolved()->create(['target_type' => Post::class, 'target_id' => $post->id]);
    Report::factory()->resolved()->create(['target_type' => Comment::class, 'target_id' => $reply->id]);

    app(DeletePostAction::class)->handle($owner, $post);

    return [$post->fresh() ?? Post::withTrashed()->findOrFail($post->id), $tag, $owner];
}

it('purges an expired author-deleted post with its entire graph, keeping unrelated records', function () {
    [$post, $tag, $owner] = purgeableGraph();
    $assetId = $post->image_asset_id;

    $unrelated = Post::factory()->published()->create();
    $unrelatedComment = Comment::factory()->create(['post_id' => $unrelated->id]);

    $this->travel(31)->days();

    $outcome = app(PostRetentionPurgeService::class)->purge($post->id);

    expect($outcome)->toBe(PostPurgeOutcome::Purged)
        ->and(Post::withTrashed()->find($post->id))->toBeNull()
        ->and(Comment::withTrashed()->where('post_id', $post->id)->count())->toBe(0)
        ->and(CommentVote::query()->count())->toBe(0)
        ->and(PostVote::query()->where('post_id', $post->id)->count())->toBe(0)
        ->and(RatingVote::query()->where('post_id', $post->id)->count())->toBe(0)
        ->and(PostSave::query()->where('post_id', $post->id)->count())->toBe(0)
        ->and(PostAuthorAnswer::query()->where('post_id', $post->id)->count())->toBe(0)
        ->and(Report::query()->count())->toBe(0)
        ->and(DB::table('post_tag')->where('post_id', $post->id)->count())->toBe(0);

    // Unrelated records and the actors themselves survive.
    expect(Tag::query()->find($tag->id))->not->toBeNull()
        ->and(User::query()->find($owner->id))->not->toBeNull()
        ->and(Post::query()->find($unrelated->id))->not->toBeNull()
        ->and(Comment::query()->find($unrelatedComment->id))->not->toBeNull();

    // Final reference gone: asset released (soft-deleted), not hard-deleted.
    $asset = MediaAsset::withTrashed()->findOrFail($assetId);
    expect($asset->trashed())->toBeTrue();
});

it('skips a deleted post younger than retention and mutates nothing', function () {
    [$post] = purgeableGraph();

    $this->travel(29)->days();

    $outcome = app(PostRetentionPurgeService::class)->purge($post->id);

    expect($outcome)->toBe(PostPurgeOutcome::NotExpired)
        ->and(Post::withTrashed()->find($post->id))->not->toBeNull()
        ->and(Comment::withTrashed()->where('post_id', $post->id)->count())->toBe(2)
        ->and(MediaAsset::withTrashed()->findOrFail($post->image_asset_id)->trashed())->toBeFalse();
});

it('purges exactly at the retention cutoff', function () {
    [$post] = purgeableGraph();

    $this->travelTo($post->deleted_at->copy()->addDays(30));

    expect(app(PostRetentionPurgeService::class)->purge($post->id))->toBe(PostPurgeOutcome::Purged);
});

it('reports would_purge on dry-run and mutates nothing', function () {
    [$post] = purgeableGraph();

    $this->travel(31)->days();

    $outcome = app(PostRetentionPurgeService::class)->purge($post->id, dryRun: true);

    expect($outcome)->toBe(PostPurgeOutcome::WouldPurge)
        ->and(Post::withTrashed()->find($post->id))->not->toBeNull()
        ->and(Comment::withTrashed()->where('post_id', $post->id)->count())->toBe(2)
        ->and(Report::query()->count())->toBe(2)
        ->and(MediaAsset::withTrashed()->findOrFail($post->image_asset_id)->trashed())->toBeFalse();
});

it('blocks the purge while moderation evidence is active', function (string $holdKind) {
    [$post] = purgeableGraph();

    match ($holdKind) {
        'needs_review' => Post::withTrashed()->whereKey($post->id)->update(['needs_review' => true]),
        'open post report' => Report::factory()->create([
            'target_type' => Post::class,
            'target_id' => $post->id,
            'status' => ReportStatus::Open,
        ]),
        'open comment report' => Report::factory()->create([
            'target_type' => Comment::class,
            'target_id' => Comment::withTrashed()->where('post_id', $post->id)->first()->id,
            'status' => ReportStatus::Open,
        ]),
    };

    $this->travel(31)->days();

    $service = app(PostRetentionPurgeService::class);

    expect($service->purge($post->id))->toBe(PostPurgeOutcome::ModerationHold)
        ->and(Post::withTrashed()->find($post->id))->not->toBeNull()
        ->and(MediaAsset::withTrashed()->findOrFail($post->image_asset_id)->trashed())->toBeFalse();

    // Resolving the hold with real application semantics unblocks the purge.
    Post::withTrashed()->whereKey($post->id)->update(['needs_review' => false]);
    Report::query()->where('status', ReportStatus::Open)->update([
        'status' => ReportStatus::Resolved,
        'resolved_by' => User::factory()->moderator()->create()->id,
        'resolved_at' => now(),
    ]);

    expect($service->purge($post->id))->toBe(PostPurgeOutcome::Purged);
})->with(['needs_review', 'open post report', 'open comment report']);

it('fails closed on rows outside the well-formed author-deletion shape', function () {
    $service = app(PostRetentionPurgeService::class);
    $this->travel(31)->days();

    // Live post.
    $live = Post::factory()->published()->create();
    expect($service->purge($live->id))->toBe(PostPurgeOutcome::InvalidState);

    // Hidden post.
    $hidden = Post::factory()->hidden()->create();
    expect($service->purge($hidden->id))->toBe(PostPurgeOutcome::InvalidState);

    // status Deleted but not soft-deleted.
    $notTrashed = Post::factory()->create(['status' => PostStatus::Deleted, 'deleted_from_status' => PostStatus::Published]);
    expect($service->purge($notTrashed->id))->toBe(PostPurgeOutcome::InvalidState);

    // Soft-deleted with non-Deleted status (legacy admin shape).
    $legacy = Post::factory()->published()->create();
    Post::query()->whereKey($legacy->id)->update(['deleted_at' => now()->subDays(60)]);
    expect($service->purge($legacy->id))->toBe(PostPurgeOutcome::InvalidState);

    // Author-deleted without a valid captured source.
    $noSource = Post::factory()->authorDeleted()->create(['deleted_from_status' => null, 'deleted_at' => now()->subDays(60)]);
    expect($service->purge($noSource->id))->toBe(PostPurgeOutcome::InvalidState);

    // Everything still present.
    expect(Post::withTrashed()->count())->toBe(5);
});

it('reports already_gone for a missing post id', function () {
    expect(app(PostRetentionPurgeService::class)->purge(999999))->toBe(PostPurgeOutcome::AlreadyGone);
});

it('rejects an explicit negative retention override', function () {
    [$post] = purgeableGraph();

    expect(fn () => app(PostRetentionPurgeService::class)->purge($post->id, olderThanDays: -1))
        ->toThrow(InvalidArgumentException::class);

    expect(Post::withTrashed()->find($post->id))->not->toBeNull();
});

it('keeps a shared media asset active when one referencing post is purged', function () {
    $asset = MediaAsset::factory()->postImage()->create();

    $owner = User::factory()->create();
    $purged = Post::factory()->published()->for($owner)->create(['image_asset_id' => $asset->id]);
    Post::factory()->published()->create(['image_asset_id' => $asset->id]);

    app(DeletePostAction::class)->handle($owner, $purged);
    $this->travel(31)->days();

    expect(app(PostRetentionPurgeService::class)->purge($purged->id))->toBe(PostPurgeOutcome::Purged);

    expect(MediaAsset::withTrashed()->findOrFail($asset->id)->trashed())->toBeFalse();
});

it('soft-deletes the final-reference asset without touching the physical file', function () {
    Storage::fake('public');
    Storage::disk('public')->put('posts/final.jpg', 'bytes');

    $asset = MediaAsset::factory()->postImage()->create(['disk' => 'public', 'path' => 'posts/final.jpg']);

    $owner = User::factory()->create();
    $post = Post::factory()->published()->for($owner)->create(['image_asset_id' => $asset->id]);

    app(DeletePostAction::class)->handle($owner, $post);
    $this->travel(31)->days();

    expect(app(PostRetentionPurgeService::class)->purge($post->id))->toBe(PostPurgeOutcome::Purged);

    expect(MediaAsset::withTrashed()->findOrFail($asset->id)->trashed())->toBeTrue()
        ->and(Storage::disk('public')->exists('posts/final.jpg'))->toBeTrue();
});

it('keeps moderation logs after the purge', function () {
    [$post] = purgeableGraph();

    $log = ModerationLog::create([
        'moderator_id' => User::factory()->moderator()->create()->id,
        'action' => ModerationActionType::HidePost,
        'target_type' => Post::class,
        'target_id' => $post->id,
        'reason' => 'audit trail',
        'metadata' => [],
    ]);

    $this->travel(31)->days();

    expect(app(PostRetentionPurgeService::class)->purge($post->id))->toBe(PostPurgeOutcome::Purged)
        ->and(ModerationLog::query()->find($log->id))->not->toBeNull();
});

it('rolls back the whole graph when any cleanup step fails', function () {
    [$post] = purgeableGraph();
    $assetId = $post->image_asset_id;

    $this->travel(31)->days();

    // Fail the very last in-transaction step: the media release's
    // reference check (MediaLifecycleService itself is final).
    $this->mock(MediaReferenceChecker::class)
        ->shouldReceive('referencedAssetIds')
        ->once()
        ->andThrow(new RuntimeException('boom'));

    $service = app(PostRetentionPurgeService::class);

    expect(fn () => $service->purge($post->id))->toThrow(RuntimeException::class);

    // Nothing was partially destroyed.
    expect(Post::withTrashed()->find($post->id))->not->toBeNull()
        ->and(Comment::withTrashed()->where('post_id', $post->id)->count())->toBe(2)
        ->and(CommentVote::query()->count())->toBe(1)
        ->and(PostVote::query()->where('post_id', $post->id)->count())->toBe(1)
        ->and(RatingVote::query()->where('post_id', $post->id)->count())->toBe(1)
        ->and(PostSave::query()->where('post_id', $post->id)->count())->toBe(1)
        ->and(PostAuthorAnswer::query()->where('post_id', $post->id)->count())->toBe(1)
        ->and(Report::query()->count())->toBe(2)
        ->and(MediaAsset::withTrashed()->findOrFail($assetId)->trashed())->toBeFalse();
});

it('re-checks eligibility under lock so concurrent purges cannot double-destroy', function () {
    // Single-process proxy for the concurrent case: the second purge of the
    // same id re-reads under lock and finds the row gone.
    [$post] = purgeableGraph();
    $this->travel(31)->days();

    $service = app(PostRetentionPurgeService::class);

    expect($service->purge($post->id))->toBe(PostPurgeOutcome::Purged)
        ->and($service->purge($post->id))->toBe(PostPurgeOutcome::AlreadyGone);
});

it('exposes candidates as a pre-filter that excludes malformed and young rows', function () {
    [$expired] = purgeableGraph();
    $this->travel(31)->days();

    // Young author-deleted post (deleted now, 31 days after the first).
    $owner = User::factory()->create();
    $young = Post::factory()->published()->for($owner)->create();
    app(DeletePostAction::class)->handle($owner, $young);

    // Malformed soft-deleted row.
    $legacy = Post::factory()->published()->create();
    Post::query()->whereKey($legacy->id)->update(['deleted_at' => now()->subDays(60)]);

    $ids = app(PostRetentionPurgeService::class)->candidates()->pluck('id');

    expect($ids)->toContain($expired->id)
        ->not->toContain($young->id)
        // The legacy row still has a non-Deleted status: not a candidate.
        ->and($ids)->not->toContain($legacy->id);
});
