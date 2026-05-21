<?php

use App\Models\Tag;
use App\Models\User;

it('allows admin to delete a tag', function () {
    $admin = User::factory()->admin()->create();
    $tag = Tag::factory()->create();

    expect($admin->can('delete', $tag))->toBeTrue();
});

it('does not allow moderator to delete a tag', function () {
    $moderator = User::factory()->moderator()->create();
    $tag = Tag::factory()->create();

    expect($moderator->can('delete', $tag))->toBeFalse();
});

it('does not allow normal user to delete a tag', function () {
    $user = User::factory()->create();
    $tag = Tag::factory()->create();

    expect($user->can('delete', $tag))->toBeFalse();
});
