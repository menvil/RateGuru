<?php

use App\Models\Post;
use App\Models\User;

dataset('moderation abilities', ['approve', 'reject', 'hide', 'restore']);

it('allows moderator to perform moderation ability', function (string $ability) {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->create();

    expect($moderator->can($ability, $post))->toBeTrue();
})->with('moderation abilities');

it('allows admin to perform moderation ability', function (string $ability) {
    $admin = User::factory()->admin()->create();
    $post = Post::factory()->create();

    expect($admin->can($ability, $post))->toBeTrue();
})->with('moderation abilities');

it('does not allow normal user to perform moderation ability', function (string $ability) {
    $user = User::factory()->create();
    $post = Post::factory()->create();

    expect($user->can($ability, $post))->toBeFalse();
})->with('moderation abilities');

it('allows only admin to delete a post', function () {
    $admin = User::factory()->admin()->create();
    $moderator = User::factory()->moderator()->create();
    $user = User::factory()->create();
    $post = Post::factory()->create();

    expect($admin->can('delete', $post))->toBeTrue();
    expect($moderator->can('delete', $post))->toBeFalse();
    expect($user->can('delete', $post))->toBeFalse();
});
