<?php

use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;

/*
 * /up, the health endpoint every deploy, restore and recovery checks: it
 * answers for the database and the cache store, and says nothing about either
 * on the page itself.
 */

beforeEach(function () {
    // In debug mode the health route rethrows instead of answering; a server
    // never runs in it.
    config(['app.debug' => false]);
    Exceptions::fake();
});

it('answers up while the database and the cache store answer', function () {
    $this->get('/up')->assertOk();
    $this->getJson('/up')->assertOk()->assertExactJson(['status' => 'up']);

    Exceptions::assertNothingReported();
});

it('answers down when the database cannot be reached, and reports why without showing it', function () {
    $default = config('database.default');
    config([
        'database.connections.unreachable' => ['driver' => 'sqlite', 'database' => '/nonexistent/rateguru-health.sqlite'],
        'database.default' => 'unreachable',
    ]);

    try {
        $page = $this->get('/up')->assertStatus(500)->getContent();
        $this->getJson('/up')->assertStatus(500)->assertExactJson(['status' => 'down']);
    } finally {
        // The test's own transaction is rolled back on the real connection.
        config(['database.default' => $default]);
    }

    expect($page)->toContain('experiencing problems')->not->toContain('rateguru-health.sqlite');
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'The database cannot be reached.' && $e->getPrevious() !== null);
});

it('answers down when the cache store cannot be reached', function () {
    Cache::extend('unreachable', fn () => Cache::repository(new class extends ArrayStore
    {
        public function get($key): mixed
        {
            throw new RuntimeException('connection refused');
        }
    }));
    config(['cache.stores.unreachable' => ['driver' => 'unreachable'], 'cache.default' => 'unreachable']);

    $this->getJson('/up')->assertStatus(500)->assertExactJson(['status' => 'down']);

    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'The cache store cannot be reached.');
});
