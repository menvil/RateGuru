<?php

use App\Actions\Posts\DeletePostAction;
use App\Models\Post;
use App\Models\User;
use App\Services\Posts\PostRetentionPurgeService;
use App\Support\Posts\PostRetention;

/*
 * Fail-closed retention resolution: a misconfigured
 * POST_AUTHOR_DELETE_RETENTION_DAYS must stop every retention computation
 * with an exception — it must never silently collapse to 0 and feed an
 * immediate destructive purge.
 */

it('accepts valid retention values', function (mixed $configured, int $expected) {
    config(['posts.author_delete_retention_days' => $configured]);

    expect(PostRetention::days())->toBe($expected);
})->with([
    'integer zero' => [0, 0],
    'integer thirty' => [30, 30],
    'env-style string' => ['30', 30],
    'env-style zero string' => ['0', 0],
]);

it('fails closed on invalid retention values', function (mixed $configured) {
    config(['posts.author_delete_retention_days' => $configured]);

    expect(fn () => PostRetention::days())->toThrow(InvalidArgumentException::class);
})->with([
    'negative integer' => [-1],
    'negative env string' => ['-30'],
    'non-numeric env string' => ['foo'],
    'float-like string' => ['1.5'],
    'null' => [null],
]);

it('stops the purge service instead of purging immediately on bad config', function (mixed $configured) {
    $owner = User::factory()->create();
    $post = Post::factory()->published()->for($owner)->create();
    app(DeletePostAction::class)->handle($owner, $post);

    config(['posts.author_delete_retention_days' => $configured]);

    expect(fn () => app(PostRetentionPurgeService::class)->purge($post->id))
        ->toThrow(InvalidArgumentException::class);

    expect(Post::withTrashed()->find($post->id))->not->toBeNull();
})->with([
    'negative' => [-30],
    'non-numeric' => ['foo'],
]);

it('fails the posts:purge run cleanly on bad config without touching any post', function () {
    $owner = User::factory()->create();
    $post = Post::factory()->published()->for($owner)->create();
    app(DeletePostAction::class)->handle($owner, $post);

    config(['posts.author_delete_retention_days' => 'foo']);

    $this->artisan('posts:purge')->assertFailed();

    expect(Post::withTrashed()->find($post->id))->not->toBeNull();
});

it('still honors an explicit --older-than override under bad config', function () {
    // The override never consults the broken config value; the run is
    // explicit and validated at the option boundary.
    $owner = User::factory()->create();
    $post = Post::factory()->published()->for($owner)->create();
    app(DeletePostAction::class)->handle($owner, $post);
    Post::withTrashed()->whereKey($post->id)->update(['deleted_at' => now()->subDays(40)]);

    config(['posts.author_delete_retention_days' => 'foo']);

    $this->artisan('posts:purge --older-than=30')->assertSuccessful();

    expect(Post::withTrashed()->find($post->id))->toBeNull();
});

it('fails closed for the restore boundary as well', function () {
    $owner = User::factory()->create();
    $post = Post::factory()->published()->for($owner)->create();
    app(DeletePostAction::class)->handle($owner, $post);

    config(['posts.author_delete_retention_days' => -30]);

    $trashed = Post::withTrashed()->findOrFail($post->id);

    // Neither "expired" nor "restorable": the computation itself refuses.
    expect(fn () => $trashed->isAuthorRestorable())->toThrow(InvalidArgumentException::class);
});
