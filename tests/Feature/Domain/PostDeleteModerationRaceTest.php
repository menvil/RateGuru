<?php

use App\Actions\Moderation\HidePostAction;
use App\Actions\Moderation\RestorePostAction;
use App\Actions\Posts\DeletePostAction;
use App\Enums\PostStatus;
use App\Exceptions\Moderation\CannotModeratePostException;
use App\Exceptions\Posts\CannotDeletePostException;
use App\Models\Post;
use App\Models\User;

/*
 * Delete vs moderation ordering is deterministic via the posts row lock:
 * whichever side commits first wins, the loser is rejected on its locked
 * re-read (docs/architecture/post-lifecycle.md).
 */

it('hide first: author delete is rejected and no retention clock starts', function () {
    $owner = User::factory()->create();
    $moderator = User::factory()->moderator()->create();
    $stalePost = Post::factory()->published()->for($owner)->create();

    app(HidePostAction::class)->handle($moderator, $stalePost->fresh());

    expect(fn () => app(DeletePostAction::class)->handle($owner, $stalePost))
        ->toThrow(CannotDeletePostException::class);

    $fresh = Post::withTrashed()->findOrFail($stalePost->id);
    expect($fresh->status)->toBe(PostStatus::Hidden)
        ->and($fresh->deleted_at)->toBeNull();
});

it('author delete first: a later hide is rejected on its locked re-read', function () {
    $owner = User::factory()->create();
    $moderator = User::factory()->moderator()->create();
    $stalePost = Post::factory()->published()->for($owner)->create();

    app(DeletePostAction::class)->handle($owner, $stalePost->fresh());

    expect(fn () => app(HidePostAction::class)->handle($moderator, $stalePost))
        ->toThrow(CannotModeratePostException::class);

    $fresh = Post::withTrashed()->findOrFail($stalePost->id);
    expect($fresh->status)->toBe(PostStatus::Deleted)
        ->and($fresh->trashed())->toBeTrue();
});

it('moderation restore can never resurrect an author-deleted post', function () {
    $owner = User::factory()->create();
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->for($owner)->create();

    app(DeletePostAction::class)->handle($owner, $post->fresh());

    $trashed = Post::withTrashed()->findOrFail($post->id);

    expect(fn () => app(RestorePostAction::class)->handle($moderator, $trashed))
        ->toThrow(CannotModeratePostException::class);

    expect(Post::withTrashed()->findOrFail($post->id)->trashed())->toBeTrue();
});

it('moderator restore of a Hidden post returns it to Published, after which the owner may delete', function () {
    $owner = User::factory()->create();
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->for($owner)->create();

    app(HidePostAction::class)->handle($moderator, $post);
    app(RestorePostAction::class)->handle($moderator, $post->fresh());

    expect($post->fresh()->status)->toBe(PostStatus::Published);

    app(DeletePostAction::class)->handle($owner, $post->fresh());

    $fresh = Post::withTrashed()->findOrFail($post->id);
    expect($fresh->status)->toBe(PostStatus::Deleted)
        ->and($fresh->deleted_from_status)->toBe(PostStatus::Published);
});
