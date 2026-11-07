<?php

use App\Actions\Follows\ToggleFollowAuthorAction;
use App\Exceptions\Follows\FollowFeatureDisabledException;
use App\Models\Follow;
use App\Models\ProjectSettings;
use App\Models\User;
use Illuminate\Support\Facades\Log;

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

it('records a follow or unfollow attempted while the feature is off, on the path the page takes', function (bool $alreadyFollowing, string $action) {
    ProjectSettings::factory()->create(['feature_flags' => ['show_follow_buttons' => false]]);
    Log::spy();
    $follower = User::factory()->create();
    $author = User::factory()->create();

    if ($alreadyFollowing) {
        Follow::factory()->create(['follower_id' => $follower->id, 'author_id' => $author->id]);
    }

    expect(fn () => app(ToggleFollowAuthorAction::class)->handle($follower, $author))
        ->toThrow(FollowFeatureDisabledException::class);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'security.feature_disabled_action_attempted'
        && $context['feature'] === 'show_follow_buttons'
        && $context['action'] === $action);
})->with([
    'a follow' => [false, 'follow'],
    'an unfollow' => [true, 'unfollow'],
]);
