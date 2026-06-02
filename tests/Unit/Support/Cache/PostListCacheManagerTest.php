<?php

use App\Support\Cache\PostListCacheManager;
use Tests\TestCase;

uses(TestCase::class);

it('resolves post list cache manager', function () {
    expect(app(PostListCacheManager::class))
        ->toBeInstanceOf(PostListCacheManager::class);
});

it('post list cache placeholder returns callback result', function () {
    $result = app(PostListCacheManager::class)->remember(
        key: 'feed:newest:page:1',
        callback: fn () => 'fresh-result',
    );

    expect($result)->toBe('fresh-result');
});

it('generates stable post list cache keys from filters', function () {
    $key = app(PostListCacheManager::class)->keyForFeed([
        'sort' => 'newest',
        'search' => 'pasta',
        'tag' => 'italian',
        'page' => 1,
        'perPage' => 12,
    ]);

    expect($key)->toContain('post-list:feed');
    expect($key)->toContain('sort="newest"');
    expect($key)->toContain('search="pasta"');
    expect($key)->toBe('post-list:feed:page=1:perPage=12:search="pasta":sort="newest":tag="italian"');
});
