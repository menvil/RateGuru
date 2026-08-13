<?php

use App\Actions\Profile\AnonymizeUserAccountAction;
use App\Enums\UserStatus;
use App\Models\Post;
use App\Models\User;
use App\Policies\PostPolicy;
use Illuminate\Support\Facades\Gate;

it('has post policy registered', function () {
    expect(Gate::getPolicyFor(Post::class))->toBeInstanceOf(PostPolicy::class);
});

it('has expected post policy methods', function () {
    $policy = app(PostPolicy::class);

    expect(method_exists($policy, 'create'))->toBeTrue();
    expect(method_exists($policy, 'update'))->toBeTrue();
    expect(method_exists($policy, 'hide'))->toBeTrue();
    expect(method_exists($policy, 'deleteFromFeed'))->toBeTrue();
    expect(method_exists($policy, 'report'))->toBeTrue();
    expect(method_exists($policy, 'vote'))->toBeTrue();
});

it('allows active users to create posts through the post policy', function () {
    $user = User::factory()->create();

    expect($user->can('create', Post::class))->toBeTrue();
});

it('does not allow banned users to create posts through the post policy', function () {
    $user = User::factory()->banned()->create();

    expect($user->can('create', Post::class))->toBeFalse();
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

it('exposes no generic admin delete ability anymore', function () {
    // Moderation acts through hide/restore; author deletion is owner-only.
    // The old admin `delete` ability entered author retention ambiguously
    // and was removed together with DeletePostInAdminAction.
    expect(method_exists(new PostPolicy, 'delete'))->toBeFalse();
});

it('restricts author deletion to the post owner', function () {
    $admin = User::factory()->admin()->create();
    $moderator = User::factory()->moderator()->create();
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $post = Post::factory()->for($owner)->create();

    expect($admin->can('deleteFromFeed', $post))->toBeFalse();
    expect($moderator->can('deleteFromFeed', $post))->toBeFalse();
    expect($owner->can('deleteFromFeed', $post))->toBeTrue();
    expect($other->can('deleteFromFeed', $post))->toBeFalse();
});

it('restricts author restore to the post owner', function () {
    $admin = User::factory()->admin()->create();
    $moderator = User::factory()->moderator()->create();
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $post = Post::factory()->authorDeleted()->for($owner)->create();

    expect($owner->can('restoreDeleted', $post))->toBeTrue();
    expect($admin->can('restoreDeleted', $post))->toBeFalse();
    expect($moderator->can('restoreDeleted', $post))->toBeFalse();
    expect($other->can('restoreDeleted', $post))->toBeFalse();
});

it('does not let a tombstoned owner author-delete content', function () {
    $owner = User::factory()->create();
    $post = Post::factory()->for($owner)->published()->create();

    app(AnonymizeUserAccountAction::class)->execute($owner);

    expect($owner->fresh()->can('deleteFromFeed', $post))->toBeFalse();
});

it('allows users to report another users post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    expect($user->can('report', $post))->toBeTrue();
});

it('does not allow users to report their own post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->for($user)->published()->create();

    expect($user->can('report', $post))->toBeFalse();
});

it('allows users to vote on another users post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    expect($user->can('vote', $post))->toBeTrue();
});

it('does not allow users to vote on their own post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->for($user)->published()->create();

    expect($user->can('vote', $post))->toBeFalse();
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

/*
 * Gate and action layers must agree on lifecycle semantics: whatever the
 * participation actions reject via the capability contract, @can() must
 * also deny, so presentation never renders controls the action would
 * refuse (e.g. vote buttons for a banned user).
 */

dataset('lifecycle gate matrix', [
    'active' => [UserStatus::Active, true],
    'limited' => [UserStatus::Limited, false],
    'banned' => [UserStatus::Banned, false],
    'shadowbanned' => [UserStatus::Shadowbanned, false],
    'deleted' => [UserStatus::Deleted, false],
]);

it('applies the lifecycle capability matrix to the vote gate', function (
    UserStatus $status,
    bool $allowed,
) {
    $user = User::factory()->create(['status' => $status]);
    $post = Post::factory()->published()->create();

    expect($user->can('vote', $post))->toBe($allowed);
})->with('lifecycle gate matrix');

it('applies the lifecycle capability matrix to the report gate', function (
    UserStatus $status,
    bool $allowed,
) {
    $user = User::factory()->create(['status' => $status]);
    $post = Post::factory()->published()->create();

    expect($user->can('report', $post))->toBe($allowed);
})->with('lifecycle gate matrix');

it('applies the lifecycle capability matrix to the create gate', function (
    UserStatus $status,
    bool $allowed,
) {
    $user = User::factory()->create(['status' => $status]);

    expect($user->can('create', Post::class))->toBe($allowed);
})->with('lifecycle gate matrix');
