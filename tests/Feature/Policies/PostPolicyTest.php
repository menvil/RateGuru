<?php

use App\Enums\UserStatus;
use App\Models\Post;
use App\Models\User;
use App\Policies\PostPolicy;
use Illuminate\Support\Facades\Gate;

it('has post policy registered', function () {
    expect(Gate::getPolicyFor(Post::class))->toBeInstanceOf(PostPolicy::class);
});

/*
 * Gate and action layers must agree on lifecycle semantics: whatever
 * VotePostAction rejects via canVote(), @can('vote', $post) must also deny,
 * so presentation never renders vote controls the action would refuse.
 */
it('applies the lifecycle capability matrix to the vote gate', function (
    UserStatus $status,
    bool $allowed,
) {
    $user = User::factory()->create(['status' => $status]);
    $post = Post::factory()->published()->create();

    expect($user->can('vote', $post))->toBe($allowed);
})->with([
    'active' => [UserStatus::Active, true],
    'limited' => [UserStatus::Limited, false],
    'banned' => [UserStatus::Banned, false],
    'shadowbanned' => [UserStatus::Shadowbanned, false],
]);

it('denies the vote gate on an own post even for an active user', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->for($user)->create();

    expect($user->can('vote', $post))->toBeFalse();
});

it('applies the lifecycle capability matrix to the report gate', function (
    UserStatus $status,
    bool $allowed,
) {
    $user = User::factory()->create(['status' => $status]);
    $post = Post::factory()->published()->create();

    expect($user->can('report', $post))->toBe($allowed);
})->with([
    'active' => [UserStatus::Active, true],
    'limited' => [UserStatus::Limited, false],
    'banned' => [UserStatus::Banned, false],
    'shadowbanned' => [UserStatus::Shadowbanned, false],
]);

it('applies the lifecycle capability matrix to the create gate', function (
    UserStatus $status,
    bool $allowed,
) {
    $user = User::factory()->create(['status' => $status]);

    expect($user->can('create', Post::class))->toBe($allowed);
})->with([
    'active' => [UserStatus::Active, true],
    'limited' => [UserStatus::Limited, false],
    'banned' => [UserStatus::Banned, false],
    'shadowbanned' => [UserStatus::Shadowbanned, false],
]);
