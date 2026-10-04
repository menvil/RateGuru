<?php

use App\Enums\CommentStatus;
use App\Models\Comment;
use App\Models\User;
use App\Policies\CommentPolicy;
use Illuminate\Support\Facades\Gate;

it('has comment policy registered', function () {
    expect(Gate::getPolicyFor(Comment::class))->toBeInstanceOf(CommentPolicy::class);
});

it('has expected comment policy methods', function () {
    $policy = app(CommentPolicy::class);

    expect(method_exists($policy, 'delete'))->toBeTrue();
    expect(method_exists($policy, 'hide'))->toBeTrue();
});

it('allows user to delete own comment', function () {
    $user = User::factory()->create();

    $comment = Comment::factory()->for($user)->create();

    expect($user->can('delete', $comment))->toBeTrue();
});

it('does not allow user to delete another users comment', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $comment = Comment::factory()->for($owner)->create();

    expect($other->can('delete', $comment))->toBeFalse();
});

it('does not allow admin to use author deletion on another users comment', function () {
    // PR-D: comment deletion is an authored-content decision, owner-only.
    // Admins/moderators act through hide/restore instead.
    $admin = User::factory()->admin()->create();
    $owner = User::factory()->create();
    $comment = Comment::factory()->for($owner)->create();

    expect($admin->can('delete', $comment))->toBeFalse();
});

it('does not allow moderator to delete comment by default', function () {
    $moderator = User::factory()->moderator()->create();
    $owner = User::factory()->create();
    $comment = Comment::factory()->for($owner)->create();

    expect($moderator->can('delete', $comment))->toBeFalse();
});

dataset('comment moderation abilities', ['hide', 'restore']);

it('allows moderator to moderate a comment in the matching state', function (string $ability) {
    $moderator = User::factory()->moderator()->create();
    $comment = Comment::factory()->for(User::factory())->create([
        'status' => $ability === 'restore' ? CommentStatus::Hidden : CommentStatus::Visible,
    ]);

    expect($moderator->can($ability, $comment))->toBeTrue();
})->with('comment moderation abilities');

it('allows admin to moderate a comment in the matching state', function (string $ability) {
    $admin = User::factory()->admin()->create();
    $comment = Comment::factory()->for(User::factory())->create([
        'status' => $ability === 'restore' ? CommentStatus::Hidden : CommentStatus::Visible,
    ]);

    expect($admin->can($ability, $comment))->toBeTrue();
})->with('comment moderation abilities');

it('refuses hide and restore on an author-deleted comment', function (string $ability) {
    $admin = User::factory()->admin()->create();
    $comment = Comment::factory()->for(User::factory())->create([
        'status' => $ability === 'restore' ? CommentStatus::Hidden : CommentStatus::Visible,
    ]);
    $comment->delete();

    expect($admin->can($ability, $comment->fresh()))->toBeFalse();
})->with('comment moderation abilities');

it('refuses restore on a visible comment', function () {
    $admin = User::factory()->admin()->create();
    $comment = Comment::factory()->for(User::factory())->create();

    expect($admin->can('restore', $comment))->toBeFalse();
});

it('does not allow comment owner without role to moderate their comment', function (string $ability) {
    $owner = User::factory()->create();
    $comment = Comment::factory()->for($owner)->create();

    expect($owner->can($ability, $comment))->toBeFalse();
})->with('comment moderation abilities');

it('does not allow moderator to hide already hidden comment', function () {
    $moderator = User::factory()->moderator()->create();

    $comment = Comment::factory()->create([
        'status' => CommentStatus::Hidden,
    ]);

    expect($moderator->can('hide', $comment))->toBeFalse();
});
