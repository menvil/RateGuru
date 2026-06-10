<?php

use App\Actions\Follows\FollowAuthorAction;
use App\Exceptions\Follows\CannotFollowAuthorException;
use App\Exceptions\Follows\CannotFollowSelfException;
use App\Exceptions\Follows\FollowFeatureDisabledException;
use App\Models\Follow;
use App\Models\ProjectSettings;
use App\Models\User;

it('allows user to follow author', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_follow_buttons' => true]]);

    $follower = User::factory()->create();
    $author = User::factory()->create();

    app(FollowAuthorAction::class)->handle($follower, $author);

    $this->assertDatabaseHas('follows', [
        'follower_id' => $follower->id,
        'author_id' => $author->id,
    ]);
});

it('is idempotent when following same author twice', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_follow_buttons' => true]]);

    $follower = User::factory()->create();
    $author = User::factory()->create();

    app(FollowAuthorAction::class)->handle($follower, $author);
    app(FollowAuthorAction::class)->handle($follower, $author);

    expect(Follow::where('follower_id', $follower->id)->where('author_id', $author->id)->count())->toBe(1);
});

it('throws when user tries to follow themselves', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_follow_buttons' => true]]);

    $user = User::factory()->create();

    app(FollowAuthorAction::class)->handle($user, $user);
})->throws(CannotFollowSelfException::class);

it('throws when feature flag is disabled', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_follow_buttons' => false]]);

    $follower = User::factory()->create();
    $author = User::factory()->create();

    app(FollowAuthorAction::class)->handle($follower, $author);
})->throws(FollowFeatureDisabledException::class);

it('throws when author is not active', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_follow_buttons' => true]]);

    $follower = User::factory()->create();
    $author = User::factory()->banned()->create();

    app(FollowAuthorAction::class)->handle($follower, $author);
})->throws(CannotFollowAuthorException::class);
