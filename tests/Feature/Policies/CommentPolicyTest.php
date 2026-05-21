<?php

use App\Models\Comment;
use App\Models\User;

it('allows comment owner to delete comment', function () {
    $user = User::factory()->create();

    $comment = Comment::factory()->for($user)->create();

    expect($user->can('delete', $comment))->toBeTrue();
});

it('does not allow other user to delete comment', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $comment = Comment::factory()->for($owner)->create();

    expect($other->can('delete', $comment))->toBeFalse();
});

it('allows admin to delete any comment', function () {
    $admin = User::factory()->admin()->create();
    $owner = User::factory()->create();
    $comment = Comment::factory()->for($owner)->create();

    expect($admin->can('delete', $comment))->toBeTrue();
});

it('does not allow moderator to delete a comment they do not own', function () {
    $moderator = User::factory()->moderator()->create();
    $owner = User::factory()->create();
    $comment = Comment::factory()->for($owner)->create();

    expect($moderator->can('delete', $comment))->toBeFalse();
});

dataset('comment moderation abilities', ['hide', 'restore']);

it('allows moderator to moderate a comment', function (string $ability) {
    $moderator = User::factory()->moderator()->create();
    $comment = Comment::factory()->for(User::factory())->create();

    expect($moderator->can($ability, $comment))->toBeTrue();
})->with('comment moderation abilities');

it('allows admin to moderate a comment', function (string $ability) {
    $admin = User::factory()->admin()->create();
    $comment = Comment::factory()->for(User::factory())->create();

    expect($admin->can($ability, $comment))->toBeTrue();
})->with('comment moderation abilities');

it('does not allow comment owner without role to moderate their comment', function (string $ability) {
    $owner = User::factory()->create();
    $comment = Comment::factory()->for($owner)->create();

    expect($owner->can($ability, $comment))->toBeFalse();
})->with('comment moderation abilities');
