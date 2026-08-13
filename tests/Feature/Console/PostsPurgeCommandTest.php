<?php

use App\Actions\Posts\DeletePostAction;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;

beforeEach(function () {
    config(['posts.author_delete_retention_days' => 30]);
});

function authorDeletedPost(int $ageDays = 0): Post
{
    $owner = User::factory()->create();
    $post = Post::factory()->published()->for($owner)->create();
    app(DeletePostAction::class)->handle($owner, $post);

    if ($ageDays > 0) {
        Post::withTrashed()->whereKey($post->id)->update(['deleted_at' => now()->subDays($ageDays)]);
    }

    return Post::withTrashed()->findOrFail($post->id);
}

it('purges expired posts and skips young ones at the default retention', function () {
    $expired = authorDeletedPost(ageDays: 31);
    $young = authorDeletedPost();

    $this->artisan('posts:purge')
        ->expectsOutputToContain(sprintf('post %d: purged', $expired->id))
        ->expectsOutputToContain('purged: 1')
        ->assertSuccessful();

    expect(Post::withTrashed()->find($expired->id))->toBeNull()
        ->and(Post::withTrashed()->find($young->id))->not->toBeNull();
});

it('honors --older-than as a per-run retention override', function () {
    $post = authorDeletedPost(ageDays: 10);

    $this->artisan('posts:purge --older-than=5')
        ->expectsOutputToContain(sprintf('post %d: purged', $post->id))
        ->assertSuccessful();

    expect(Post::withTrashed()->find($post->id))->toBeNull();
});

it('rejects a negative or non-numeric --older-than', function (string $value) {
    $this->artisan('posts:purge --older-than='.$value)->assertFailed();
})->with(['-1', 'abc']);

it('processes a single valid --post id with a precise outcome', function () {
    $young = authorDeletedPost();

    $this->artisan('posts:purge --post='.$young->id)
        ->expectsOutputToContain(sprintf('post %d: not_expired', $young->id))
        ->assertSuccessful();

    expect(Post::withTrashed()->find($young->id))->not->toBeNull();
});

it('reports already_gone for an unknown --post id and rejects invalid ids', function () {
    $this->artisan('posts:purge --post=999999')
        ->expectsOutputToContain('post 999999: already_gone')
        ->assertSuccessful();

    $this->artisan('posts:purge --post=0')->assertFailed();
    $this->artisan('posts:purge --post=abc')->assertFailed();
});

it('reports invalid_state for a live post targeted explicitly', function () {
    $live = Post::factory()->published()->create();

    $this->artisan('posts:purge --post='.$live->id)
        ->expectsOutputToContain(sprintf('post %d: invalid_state', $live->id))
        ->assertSuccessful();

    expect(Post::query()->find($live->id))->not->toBeNull();
});

it('mutates nothing on --dry-run and reports would_purge', function () {
    $expired = authorDeletedPost(ageDays: 31);
    Comment::factory()->create(['post_id' => $expired->id]);

    $this->artisan('posts:purge --dry-run')
        ->expectsOutputToContain(sprintf('post %d: would_purge', $expired->id))
        ->expectsOutputToContain('would_purge: 1')
        ->assertSuccessful();

    expect(Post::withTrashed()->find($expired->id))->not->toBeNull()
        ->and(Comment::withTrashed()->where('post_id', $expired->id)->count())->toBe(1);
});

it('validates the --chunk option boundaries', function (string $value) {
    $this->artisan('posts:purge --chunk='.$value)->assertFailed();
})->with(['0', '-5', 'abc', '1001']);

it('accepts a small custom chunk and processes every candidate', function () {
    $first = authorDeletedPost(ageDays: 40);
    $second = authorDeletedPost(ageDays: 35);

    $this->artisan('posts:purge --chunk=1')
        ->expectsOutputToContain('purged: 2')
        ->assertSuccessful();

    expect(Post::withTrashed()->find($first->id))->toBeNull()
        ->and(Post::withTrashed()->find($second->id))->toBeNull();
});

it('reports no candidates when there is nothing to do', function () {
    $this->artisan('posts:purge')
        ->expectsOutputToContain('No purge candidates.')
        ->assertSuccessful();
});

it('counts a failing post as failed and exits with failure', function () {
    $expired = authorDeletedPost(ageDays: 31);

    $this->mock(\App\Services\Media\MediaReferenceChecker::class)
        ->shouldReceive('referencedAssetIds')
        ->andThrow(new RuntimeException('boom'));

    // A plain factory post has no asset; attach one so the purge routes
    // through the failing media release step.
    $asset = \App\Models\MediaAsset::factory()->postImage()->create();
    Post::withTrashed()->whereKey($expired->id)->update(['image_asset_id' => $asset->id]);

    $this->artisan('posts:purge')
        ->expectsOutputToContain(sprintf('post %d: failed', $expired->id))
        ->expectsOutputToContain('failed: 1')
        ->assertFailed();

    expect(Post::withTrashed()->find($expired->id))->not->toBeNull();
});
