<?php

use App\Actions\Votes\VoteCuisineAction;
use App\Actions\Votes\VoteOriginAction;
use App\Actions\Votes\VotePostAction;
use App\Enums\CuisineType;
use App\Enums\OriginType;
use App\Enums\VoteType;
use App\Exceptions\Votes\CannotVoteException;
use App\Models\Post;
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

it('calls post list cache invalidation after successful origin vote', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    $cache = Mockery::mock(PostListCacheManager::class);
    $cache->shouldReceive('invalidateForPost')->once();

    app()->instance(PostListCacheManager::class, $cache);

    app(VoteOriginAction::class)->handle($user, $post, OriginType::Homemade);
});

it('calls post list cache invalidation after successful cuisine vote', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    $cache = Mockery::mock(PostListCacheManager::class);
    $cache->shouldReceive('invalidateForPost')->once();

    app()->instance(PostListCacheManager::class, $cache);

    app(VoteCuisineAction::class)->handle($user, $post, CuisineType::Italian);
});
