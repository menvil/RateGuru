<?php

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\RatingVote;
use App\Models\User;
use App\Queries\UserRatingActivityQuery;

it('returns rating activity to profile owner', function () {
    $user = User::factory()->create(['rating_activity_visibility' => 'private']);

    RatingVote::factory()->create(['user_id' => $user->id]);

    $activity = app(UserRatingActivityQuery::class)->forProfile($user, $user);

    expect($activity)->toHaveCount(1);
});

it('does not return private rating activity to other users', function () {
    $user = User::factory()->create(['rating_activity_visibility' => 'private']);

    RatingVote::factory()->create(['user_id' => $user->id]);

    $viewer = User::factory()->create();
    $activity = app(UserRatingActivityQuery::class)->forProfile($user, $viewer);

    expect($activity)->toHaveCount(0);
});

it('does not return private rating activity to guests', function () {
    $user = User::factory()->create(['rating_activity_visibility' => 'private']);

    RatingVote::factory()->create(['user_id' => $user->id]);

    $activity = app(UserRatingActivityQuery::class)->forProfile($user, null);

    expect($activity)->toHaveCount(0);
});

it('returns rating activity publicly when visibility is public', function () {
    $user = User::factory()->create(['rating_activity_visibility' => 'public']);

    RatingVote::factory()->create(['user_id' => $user->id]);

    $viewer = User::factory()->create();
    $activity = app(UserRatingActivityQuery::class)->forProfile($user, $viewer);

    expect($activity)->toHaveCount(1);
});

it('excludes activity on hidden posts', function () {
    $user = User::factory()->create(['rating_activity_visibility' => 'public']);

    $post = Post::factory()->create(['status' => PostStatus::Hidden]);
    RatingVote::factory()->create(['user_id' => $user->id, 'post_id' => $post->id]);

    $viewer = User::factory()->create();
    $activity = app(UserRatingActivityQuery::class)->forProfile($user, $viewer);

    expect($activity)->toHaveCount(0);
});
