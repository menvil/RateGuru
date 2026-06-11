<?php

use App\Enums\PostStatus;
use App\Models\Follow;
use App\Models\Post;
use App\Models\PostSave;
use App\Models\User;
use App\Support\Profile\ProfileStats;
use App\Support\Profile\ProfileStatsData;

it('calculates public profile stats', function () {
    $user = User::factory()->create();

    Post::factory()->count(3)->for($user)->published()->create();
    Post::factory()->for($user)->create(['status' => PostStatus::Hidden]);

    Follow::factory()->count(2)->create(['author_id' => $user->id]);

    $stats = app(ProfileStats::class)->forUser($user);

    expect($stats)->toBeInstanceOf(ProfileStatsData::class);
    expect($stats->publicPostsCount)->toBe(3);
    expect($stats->followersCount)->toBe(2);
});

it('counts following for profile user', function () {
    $user = User::factory()->create();
    $authors = User::factory()->count(3)->create();

    foreach ($authors as $author) {
        Follow::factory()->create(['follower_id' => $user->id, 'author_id' => $author->id]);
    }

    $stats = app(ProfileStats::class)->forUser($user);

    expect($stats->followingCount)->toBe(3);
});

it('does not count hidden or rejected posts in public posts count', function () {
    $user = User::factory()->create();

    Post::factory()->for($user)->published()->create();
    Post::factory()->for($user)->create(['status' => PostStatus::Hidden]);
    Post::factory()->for($user)->create(['status' => PostStatus::Rejected]);
    Post::factory()->for($user)->pending()->create();

    $stats = app(ProfileStats::class)->forUser($user);

    expect($stats->publicPostsCount)->toBe(1);
});

it('shows saved posts count to owner only', function () {
    $owner = User::factory()->create();
    $post = Post::factory()->published()->create();
    PostSave::factory()->create(['user_id' => $owner->id, 'post_id' => $post->id]);

    $ownerStats = app(ProfileStats::class)->forUser($owner, $owner);
    $guestStats = app(ProfileStats::class)->forUser($owner);
    $otherUser = User::factory()->create();
    $otherStats = app(ProfileStats::class)->forUser($owner, $otherUser);

    expect($ownerStats->savedPostsCount)->toBe(1);
    expect($guestStats->savedPostsCount)->toBeNull();
    expect($otherStats->savedPostsCount)->toBeNull();
});
