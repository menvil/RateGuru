<?php

use App\Actions\Moderation\HidePostAction;
use App\Actions\Posts\DeletePostAction;
use App\Enums\PostStatus;
use App\Exceptions\Posts\CannotDeletePostException;
use App\Models\Post;
use App\Models\User;

it('allows a user to delete their own post and captures the source status', function (string $factoryState, PostStatus $expectedSource) {
    $user = User::factory()->create();
    $post = Post::factory()->{$factoryState}()->for($user)->create();

    app(DeletePostAction::class)->handle($user, $post);

    $this->assertSoftDeleted('posts', ['id' => $post->id]);

    $fresh = Post::withTrashed()->findOrFail($post->id);
    expect($fresh->status)->toBe(PostStatus::Deleted)
        ->and($fresh->deleted_from_status)->toBe($expectedSource)
        ->and($fresh->deleted_at)->not->toBeNull();
})->with([
    'draft' => ['draft', PostStatus::Draft],
    'pending' => ['pending', PostStatus::Pending],
    'published' => ['published', PostStatus::Published],
    'rejected' => ['rejected', PostStatus::Rejected],
]);

it('does not allow users to delete someone elses post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    expect(fn () => app(DeletePostAction::class)->handle($user, $post))
        ->toThrow(CannotDeletePostException::class);

    $this->assertNotSoftDeleted('posts', ['id' => $post->id]);
});

it('does not let moderators or admins author-delete a foreign post', function (string $roleState) {
    $actor = User::factory()->{$roleState}()->create();
    $post = Post::factory()->published()->create();

    expect(fn () => app(DeletePostAction::class)->handle($actor, $post))
        ->toThrow(CannotDeletePostException::class);

    $this->assertNotSoftDeleted('posts', ['id' => $post->id]);
})->with(['moderator', 'admin']);

it('rejects author deletion of a moderation-hidden post and starts no retention clock', function () {
    $user = User::factory()->create();
    $post = Post::factory()->hidden()->for($user)->create();

    expect(fn () => app(DeletePostAction::class)->handle($user, $post))
        ->toThrow(CannotDeletePostException::class, 'This post is under moderation and cannot be deleted.');

    $fresh = Post::withTrashed()->findOrFail($post->id);
    expect($fresh->deleted_at)->toBeNull()
        ->and($fresh->status)->toBe(PostStatus::Hidden)
        ->and($fresh->deleted_from_status)->toBeNull();
});

it('rejects deletion through a stale instance when moderation hid the row first', function () {
    $user = User::factory()->create();
    $stalePost = Post::factory()->published()->for($user)->create();
    $moderator = User::factory()->moderator()->create();

    app(HidePostAction::class)->handle($moderator, $stalePost->fresh());

    expect($stalePost->status)->toBe(PostStatus::Published);

    expect(fn () => app(DeletePostAction::class)->handle($user, $stalePost))
        ->toThrow(CannotDeletePostException::class);

    $fresh = Post::withTrashed()->findOrFail($stalePost->id);
    expect($fresh->deleted_at)->toBeNull()
        ->and($fresh->status)->toBe(PostStatus::Hidden);
});

it('is idempotent for an already author-deleted post without resetting the clock', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->for($user)->create();

    app(DeletePostAction::class)->handle($user, $post);

    $firstDeletedAt = Post::withTrashed()->findOrFail($post->id)->deleted_at;

    $this->travel(3)->days();

    app(DeletePostAction::class)->handle($user, Post::withTrashed()->findOrFail($post->id));

    $fresh = Post::withTrashed()->findOrFail($post->id);
    expect($fresh->deleted_at->equalTo($firstDeletedAt))->toBeTrue()
        ->and($fresh->status)->toBe(PostStatus::Deleted);
});

it('synchronizes the caller model with the deleted state', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->for($user)->create();

    app(DeletePostAction::class)->handle($user, $post);

    expect($post->status)->toBe(PostStatus::Deleted)
        ->and($post->deleted_from_status)->toBe(PostStatus::Published)
        ->and($post->trashed())->toBeTrue();
});

it('refuses a soft-deleted row whose status is not Deleted as malformed', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->for($user)->create();

    // Legacy admin-delete shape: soft-deleted without the status transition.
    Post::query()->whereKey($post->id)->update(['deleted_at' => now()]);

    expect(fn () => app(DeletePostAction::class)->handle($user, Post::withTrashed()->findOrFail($post->id)))
        ->toThrow(CannotDeletePostException::class);
});
