<?php

use App\Enums\PostStatus;
use App\Models\Post;

it('exposes trashed-aware interaction helpers', function () {
    $published = Post::factory()->published()->make();

    expect($published->canReceiveVotes())->toBeTrue()
        ->and($published->canReceiveComments())->toBeTrue()
        ->and($published->canReceiveReports())->toBeTrue()
        ->and($published->canBeSaved())->toBeTrue()
        ->and($published->canReceiveRatingVotes())->toBeTrue()
        ->and($published->isAuthorDeleted())->toBeFalse();
});

it('denies every interaction on an author-deleted post even with status still loaded', function () {
    $deleted = Post::factory()->authorDeleted()->make(['deleted_at' => now()]);

    expect($deleted->isAuthorDeleted())->toBeTrue()
        ->and($deleted->canReceiveVotes())->toBeFalse()
        ->and($deleted->canReceiveComments())->toBeFalse()
        ->and($deleted->canReceiveReports())->toBeFalse()
        ->and($deleted->canBeSaved())->toBeFalse()
        ->and($deleted->canReceiveRatingVotes())->toBeFalse();
});

it('denies every interaction on non-published statuses', function (PostStatus $status) {
    $post = Post::factory()->make(['status' => $status]);

    expect($post->canReceiveVotes())->toBeFalse()
        ->and($post->canReceiveComments())->toBeFalse()
        ->and($post->canReceiveReports())->toBeFalse()
        ->and($post->canBeSaved())->toBeFalse()
        ->and($post->canReceiveRatingVotes())->toBeFalse();
})->with([
    'draft' => PostStatus::Draft,
    'pending' => PostStatus::Pending,
    'hidden' => PostStatus::Hidden,
    'rejected' => PostStatus::Rejected,
]);

it('keeps Hidden out of the author-deletable source statuses', function () {
    expect(Post::AUTHOR_DELETABLE_STATUSES)
        ->toContain(PostStatus::Draft, PostStatus::Pending, PostStatus::Published, PostStatus::Rejected)
        ->not->toContain(PostStatus::Hidden, PostStatus::Deleted);
});

it('computes the author restore deadline from the retention config', function () {
    config(['posts.author_delete_retention_days' => 30]);

    // The datetime cast round-trips through second precision.
    $deletedAt = now()->subDays(10)->startOfSecond();
    $post = Post::factory()->authorDeleted()->make(['deleted_at' => $deletedAt]);

    expect($post->authorRestoreDeadline()?->equalTo($deletedAt->copy()->addDays(30)))->toBeTrue()
        ->and($post->isAuthorRestorable())->toBeTrue();
});

it('is restorable strictly before the cutoff and expired exactly at it', function () {
    config(['posts.author_delete_retention_days' => 30]);

    $post = Post::factory()->authorDeleted()->make(['deleted_at' => now()]);

    $this->travel(30)->days();
    expect($post->isAuthorRestorable())->toBeFalse();

    $this->travelBack();
    $this->travelTo($post->deleted_at->copy()->addDays(30)->subSecond());
    expect($post->isAuthorRestorable())->toBeTrue();
});

it('treats retention zero as immediately expired', function () {
    config(['posts.author_delete_retention_days' => 0]);

    $post = Post::factory()->authorDeleted()->make(['deleted_at' => now()]);

    expect($post->isAuthorRestorable())->toBeFalse()
        ->and($post->authorRestoreDeadline()?->equalTo($post->deleted_at))->toBeTrue();
});

it('never treats a malformed deletion state as restorable', function () {
    config(['posts.author_delete_retention_days' => 30]);

    // Soft-deleted but status not Deleted (legacy admin delete shape).
    $malformed = Post::factory()->published()->make(['deleted_at' => now()]);
    // Author-deleted but captured source is a moderation state.
    $hiddenSource = Post::factory()->authorDeleted()->make([
        'deleted_at' => now(),
        'deleted_from_status' => PostStatus::Hidden,
    ]);
    // Deleted status without a captured source.
    $noSource = Post::factory()->authorDeleted()->make([
        'deleted_at' => now(),
        'deleted_from_status' => null,
    ]);

    expect($malformed->isAuthorRestorable())->toBeFalse()
        ->and($malformed->authorRestoreDeadline())->toBeNull()
        ->and($hiddenSource->isAuthorRestorable())->toBeFalse()
        ->and($noSource->isAuthorRestorable())->toBeFalse();
});

it('persists and casts deleted_from_status', function () {
    $post = Post::factory()->authorDeleted(PostStatus::Rejected)->create();

    $fresh = Post::withTrashed()->findOrFail($post->id);

    expect($fresh->deleted_from_status)->toBe(PostStatus::Rejected)
        ->and($fresh->status)->toBe(PostStatus::Deleted);
});
