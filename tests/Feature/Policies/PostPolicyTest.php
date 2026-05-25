<?php

use App\Policies\PostPolicy;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('has post policy registered', function () {
    expect(Gate::getPolicyFor(Post::class))->toBeInstanceOf(PostPolicy::class);
});

it('has expected post policy methods', function () {
    $policy = app(PostPolicy::class);

    expect(method_exists($policy, 'update'))->toBeTrue();
    expect(method_exists($policy, 'hide'))->toBeTrue();
    expect(method_exists($policy, 'delete'))->toBeTrue();
});

it('allows user to update own draft post', function () {
    $user = User::factory()->create();

    $post = Post::factory()
        ->for($user)
        ->draft()
        ->create();

    expect($user->can('update', $post))->toBeTrue();
});

it('does not allow user to update another users draft post', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $post = Post::factory()
        ->for($owner)
        ->draft()
        ->create();

    expect($other->can('update', $post))->toBeFalse();
});

it('does not allow user to update own published post after lock rule', function () {
    $user = User::factory()->create();

    $post = Post::factory()
        ->for($user)
        ->published()
        ->create();

    expect($user->can('update', $post))->toBeFalse();
});

dataset('moderation abilities', ['approve', 'reject', 'restore']);

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

it('allows moderator to hide published post', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create();

    expect($moderator->can('hide', $post))->toBeTrue();
});

it('allows admin to hide published post', function () {
    $admin = User::factory()->admin()->create();
    $post = Post::factory()->published()->create();

    expect($admin->can('hide', $post))->toBeTrue();
});

it('does not allow normal user to hide post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    expect($user->can('hide', $post))->toBeFalse();
});

it('does not allow moderator to hide already hidden post', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->hidden()->create();

    expect($moderator->can('hide', $post))->toBeFalse();
});
