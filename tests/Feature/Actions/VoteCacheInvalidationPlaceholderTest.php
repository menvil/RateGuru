<?php

use App\Actions\Rating\VoteRatingOptionAction;
use App\Actions\Votes\VotePostAction;
use App\Enums\VoteType;
use App\Exceptions\Votes\CannotVoteException;
use App\Models\Post;
use App\Models\RatingOption;
use App\Models\User;
use App\Support\Cache\PostListCacheManager;

it('calls post list cache invalidation after successful post vote', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    $cache = Mockery::mock(PostListCacheManager::class);
    $cache->shouldReceive('invalidateForPost')
        ->once()
        ->with(Mockery::on(fn (Post $givenPost): bool => $givenPost->is($post)));

    app()->instance(PostListCacheManager::class, $cache);

    app(VotePostAction::class)->handle($user, $post, VoteType::Up);
});

it('does not invalidate post list cache after failed post vote', function () {
    $user = User::factory()->banned()->create();
    $post = Post::factory()->published()->create();

    $cache = Mockery::mock(PostListCacheManager::class);
    $cache->shouldNotReceive('invalidateForPost');

    app()->instance(PostListCacheManager::class, $cache);

    try {
        app(VotePostAction::class)->handle($user, $post, VoteType::Up);
    } catch (CannotVoteException) {
        // Expected.
    }
});

it('calls post list cache invalidation after a changed rating vote', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();
    $option = RatingOption::factory()->create();

    $cache = Mockery::mock(PostListCacheManager::class);
    $cache->shouldReceive('invalidateForPost')->once();

    app()->instance(PostListCacheManager::class, $cache);

    app(VoteRatingOptionAction::class)->handle($user, $post, $option);
});

it('does not invalidate post list cache after an unchanged rating vote', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();
    $option = RatingOption::factory()->create();

    app(VoteRatingOptionAction::class)->handle($user, $post, $option);

    $cache = Mockery::mock(PostListCacheManager::class);
    $cache->shouldNotReceive('invalidateForPost');

    app()->instance(PostListCacheManager::class, $cache);

    app(VoteRatingOptionAction::class)->handle($user, $post, $option);
});
