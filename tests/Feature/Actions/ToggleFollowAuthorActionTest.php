<?php

use App\Actions\Follows\ToggleFollowAuthorAction;
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
