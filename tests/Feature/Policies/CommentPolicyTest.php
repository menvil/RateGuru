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
