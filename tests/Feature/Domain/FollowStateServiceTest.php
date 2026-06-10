<?php

use App\Models\Follow;
use App\Models\User;
use App\Support\Follows\FollowState;

it('returns follow states for multiple authors', function () {
    $viewer = User::factory()->create();
    $authors = User::factory()->count(3)->create();

    Follow::factory()->create([
        'follower_id' => $viewer->id,
        'author_id' => $authors[0]->id,
    ]);

    $states = app(FollowState::class)->forViewerAndAuthors($viewer, $authors);

    expect($states->isFollowing($authors[0]))->toBeTrue();
    expect($states->isFollowing($authors[1]))->toBeFalse();
    expect($states->isFollowing($authors[2]))->toBeFalse();
});

it('returns all false states for guest viewer', function () {
    $authors = User::factory()->count(2)->create();

    $states = app(FollowState::class)->forViewerAndAuthors(null, $authors);

    expect($states->isFollowing($authors[0]))->toBeFalse();
    expect($states->isFollowing($authors[1]))->toBeFalse();
});

it('returns empty map for empty authors list', function () {
    $viewer = User::factory()->create();

    $states = app(FollowState::class)->forViewerAndAuthors($viewer, []);

    expect($states)->toBeInstanceOf(\App\Support\Follows\FollowStateMap::class);
});

it('returns false for single isFollowing when not following', function () {
    $viewer = User::factory()->create();
    $author = User::factory()->create();

    expect(app(FollowState::class)->isFollowing($viewer, $author))->toBeFalse();
});

it('returns true for single isFollowing when following', function () {
    $viewer = User::factory()->create();
    $author = User::factory()->create();

    Follow::factory()->create([
        'follower_id' => $viewer->id,
        'author_id' => $author->id,
    ]);

    expect(app(FollowState::class)->isFollowing($viewer, $author))->toBeTrue();
});
