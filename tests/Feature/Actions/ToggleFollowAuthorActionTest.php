<?php

use App\Actions\Follows\ToggleFollowAuthorAction;
use App\Exceptions\Follows\FollowFeatureDisabledException;
use App\Models\Follow;
use App\Models\ProjectSettings;
use App\Models\User;

it('toggles unfollowed author to followed', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_follow_buttons' => true]]);

    $follower = User::factory()->create();
    $author = User::factory()->create();

    $result = app(ToggleFollowAuthorAction::class)->handle($follower, $author);

    expect($result->isFollowing)->toBeTrue();
    $this->assertDatabaseHas('follows', [
        'follower_id' => $follower->id,
        'author_id' => $author->id,
    ]);
});

it('throws when feature flag is disabled even when already following', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_follow_buttons' => false]]);

    $follower = User::factory()->create();
    $author = User::factory()->create();

    Follow::factory()->create([
        'follower_id' => $follower->id,
        'author_id' => $author->id,
    ]);

    app(ToggleFollowAuthorAction::class)->handle($follower, $author);
})->throws(FollowFeatureDisabledException::class);

it('toggles followed author to unfollowed', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_follow_buttons' => true]]);

    $follower = User::factory()->create();
    $author = User::factory()->create();

    Follow::factory()->create([
        'follower_id' => $follower->id,
        'author_id' => $author->id,
    ]);

    $result = app(ToggleFollowAuthorAction::class)->handle($follower, $author);

    expect($result->isFollowing)->toBeFalse();
    $this->assertDatabaseMissing('follows', [
        'follower_id' => $follower->id,
        'author_id' => $author->id,
    ]);
});
