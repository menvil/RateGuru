<?php

use App\Actions\Posts\DeletePostAction;
use App\Actions\Posts\RestoreDeletedPostAction;
use App\Enums\CommentStatus;
use App\Enums\PostStatus;
use App\Exceptions\Posts\CannotRestoreDeletedPostException;
use App\Models\Comment;
use App\Models\Post;
use App\Models\PostSave;
use App\Models\PostVote;
use App\Models\User;
use App\Notifications\PostApprovedNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['posts.author_delete_retention_days' => 30]);
});

it('restores the exact prior state for every author-deletable source status', function (string $factoryState, PostStatus $expected) {
    $owner = User::factory()->create();
    $post = Post::factory()->{$factoryState}()->for($owner)->create();

    app(DeletePostAction::class)->handle($owner, $post);
    app(RestoreDeletedPostAction::class)->handle($owner, Post::withTrashed()->findOrFail($post->id));

    $fresh = Post::findOrFail($post->id);
    expect($fresh->status)->toBe($expected)
        ->and($fresh->deleted_at)->toBeNull()
        ->and($fresh->deleted_from_status)->toBeNull();
})->with([
    'draft' => ['draft', PostStatus::Draft],
    'pending' => ['pending', PostStatus::Pending],
    'published' => ['published', PostStatus::Published],
    'rejected' => ['rejected', PostStatus::Rejected],
]);

it('preserves published_at, counters, saves and comment states across restore', function () {
    Notification::fake();
    Queue::fake();

    $owner = User::factory()->create();
    $post = Post::factory()->published()->for($owner)->create([
        'upvotes_count' => 7,
        'downvotes_count' => 2,
    ]);
    $publishedAt = $post->published_at;

    $voter = User::factory()->create();
    PostVote::create(['user_id' => $voter->id, 'post_id' => $post->id, 'type' => 'up']);
    PostSave::create(['user_id' => $voter->id, 'post_id' => $post->id]);

    $liveComment = Comment::factory()->create(['post_id' => $post->id, 'status' => CommentStatus::Visible]);
    $deletedComment = Comment::factory()->create(['post_id' => $post->id, 'status' => CommentStatus::Visible]);
    $deletedComment->delete();

    app(DeletePostAction::class)->handle($owner, $post);
    app(RestoreDeletedPostAction::class)->handle($owner, Post::withTrashed()->findOrFail($post->id));

    $fresh = Post::findOrFail($post->id);

    expect($fresh->published_at?->equalTo($publishedAt))->toBeTrue()
        ->and($fresh->upvotes_count)->toBe(7)
        ->and($fresh->downvotes_count)->toBe(2)
        ->and(PostVote::query()->where('post_id', $post->id)->count())->toBe(1)
        ->and(PostSave::query()->where('post_id', $post->id)->count())->toBe(1)
        // Each comment keeps its own lifecycle: no mass comment restore.
        ->and($liveComment->fresh()->trashed())->toBeFalse()
        ->and(Comment::withTrashed()->findOrFail($deletedComment->id)->trashed())->toBeTrue();

    // Restore is not publication: no follower jobs, no approval notification.
    Notification::assertNothingSent();
    Queue::assertNothingPushed();
});

it('is owner-only', function (string $roleState) {
    $owner = User::factory()->create();
    $post = Post::factory()->published()->for($owner)->create();
    app(DeletePostAction::class)->handle($owner, $post);

    $actor = User::factory()->{$roleState}()->create();

    expect(fn () => app(RestoreDeletedPostAction::class)->handle($actor, Post::withTrashed()->findOrFail($post->id)))
        ->toThrow(CannotRestoreDeletedPostException::class);

    expect(Post::withTrashed()->findOrFail($post->id)->trashed())->toBeTrue();
})->with(['moderator', 'admin']);

it('allows restore strictly before the retention cutoff', function () {
    $owner = User::factory()->create();
    $post = Post::factory()->published()->for($owner)->create();
    app(DeletePostAction::class)->handle($owner, $post);

    $deletedAt = Post::withTrashed()->findOrFail($post->id)->deleted_at;

    $this->travelTo($deletedAt->copy()->addDays(30)->subSecond());

    app(RestoreDeletedPostAction::class)->handle($owner, Post::withTrashed()->findOrFail($post->id));

    expect(Post::findOrFail($post->id)->status)->toBe(PostStatus::Published);
});

it('rejects restore exactly at the retention cutoff even before any purge ran', function () {
    $owner = User::factory()->create();
    $post = Post::factory()->published()->for($owner)->create();
    app(DeletePostAction::class)->handle($owner, $post);

    $deletedAt = Post::withTrashed()->findOrFail($post->id)->deleted_at;

    $this->travelTo($deletedAt->copy()->addDays(30));

    expect(fn () => app(RestoreDeletedPostAction::class)->handle($owner, Post::withTrashed()->findOrFail($post->id)))
        ->toThrow(CannotRestoreDeletedPostException::class, 'The restore window for this post has expired.');

    expect(Post::withTrashed()->findOrFail($post->id)->trashed())->toBeTrue();
});

it('treats retention zero as immediately expired', function () {
    config(['posts.author_delete_retention_days' => 0]);

    $owner = User::factory()->create();
    $post = Post::factory()->published()->for($owner)->create();
    app(DeletePostAction::class)->handle($owner, $post);

    expect(fn () => app(RestoreDeletedPostAction::class)->handle($owner, Post::withTrashed()->findOrFail($post->id)))
        ->toThrow(CannotRestoreDeletedPostException::class);
});

it('refuses posts that are not author-deleted', function () {
    $owner = User::factory()->create();

    $live = Post::factory()->published()->for($owner)->create();
    expect(fn () => app(RestoreDeletedPostAction::class)->handle($owner, $live))
        ->toThrow(CannotRestoreDeletedPostException::class);

    $hidden = Post::factory()->hidden()->for($owner)->create();
    expect(fn () => app(RestoreDeletedPostAction::class)->handle($owner, $hidden))
        ->toThrow(CannotRestoreDeletedPostException::class);
});

it('fails closed on malformed deletion rows', function () {
    $owner = User::factory()->create();

    // Soft-deleted but status never became Deleted (legacy admin shape).
    $legacy = Post::factory()->published()->for($owner)->create();
    Post::query()->whereKey($legacy->id)->update(['deleted_at' => now()]);

    // Author-deleted but the captured source state is a moderation state.
    $badSource = Post::factory()->authorDeleted()->for($owner)->create([
        'deleted_from_status' => PostStatus::Hidden,
    ]);

    // Author-deleted without any captured source state.
    $noSource = Post::factory()->authorDeleted()->for($owner)->create([
        'deleted_from_status' => null,
    ]);

    foreach ([$legacy, $badSource, $noSource] as $post) {
        expect(fn () => app(RestoreDeletedPostAction::class)->handle($owner, Post::withTrashed()->findOrFail($post->id)))
            ->toThrow(CannotRestoreDeletedPostException::class);

        expect(Post::withTrashed()->findOrFail($post->id)->trashed())->toBeTrue();
    }
});

it('does not let a tombstoned owner restore content', function () {
    $owner = User::factory()->create();
    $post = Post::factory()->published()->for($owner)->create();
    app(DeletePostAction::class)->handle($owner, $post);

    app(\App\Actions\Profile\AnonymizeUserAccountAction::class)->execute($owner);

    expect(fn () => app(RestoreDeletedPostAction::class)->handle($owner->fresh(), Post::withTrashed()->findOrFail($post->id)))
        ->toThrow(CannotRestoreDeletedPostException::class);
});

it('rejects a restore through a stale instance when the window expired server-side', function () {
    $owner = User::factory()->create();
    $post = Post::factory()->published()->for($owner)->create();
    app(DeletePostAction::class)->handle($owner, $post);

    // Instance captured while restorable…
    $stale = Post::withTrashed()->findOrFail($post->id);

    // …but the authoritative clock has moved past the cutoff.
    $this->travelTo($stale->deleted_at->copy()->addDays(30));

    expect(fn () => app(RestoreDeletedPostAction::class)->handle($owner, $stale))
        ->toThrow(CannotRestoreDeletedPostException::class);
});
