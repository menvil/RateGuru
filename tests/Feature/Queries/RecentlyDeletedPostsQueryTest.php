<?php

use App\Actions\Posts\DeletePostAction;
use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;
use App\Queries\Posts\RecentlyDeletedPostsQuery;

it('returns only the owners well-formed author-deleted posts, newest deletion first', function () {
    $owner = User::factory()->create();

    $older = Post::factory()->published()->for($owner)->create();
    app(DeletePostAction::class)->handle($owner, $older);
    Post::withTrashed()->whereKey($older->id)->update(['deleted_at' => now()->subDays(2)]);

    $newer = Post::factory()->published()->for($owner)->create();
    app(DeletePostAction::class)->handle($owner, $newer);

    // Excluded: live post, foreign deleted post, legacy soft-deleted shape.
    Post::factory()->published()->for($owner)->create();

    $stranger = User::factory()->create();
    $foreign = Post::factory()->published()->for($stranger)->create();
    app(DeletePostAction::class)->handle($stranger, $foreign);

    $legacy = Post::factory()->published()->for($owner)->create();
    Post::query()->whereKey($legacy->id)->update(['deleted_at' => now()]);

    $result = app(RecentlyDeletedPostsQuery::class)->forOwner($owner);

    expect($result->pluck('id')->all())->toBe([$newer->id, $older->id]);
});

it('uses post id as the final deterministic order for equal deletion times', function () {
    $owner = User::factory()->create();
    $deletedAt = now()->startOfSecond();

    $posts = Post::factory()->published()->for($owner)->count(3)->create();

    foreach ($posts as $post) {
        app(DeletePostAction::class)->handle($owner, $post);
    }

    Post::onlyTrashed()->where('user_id', $owner->id)->update(['deleted_at' => $deletedAt]);

    $result = app(RecentlyDeletedPostsQuery::class)->forOwner($owner);

    expect($result->pluck('id')->all())->toBe($posts->pluck('id')->reverse()->values()->all());
});

it('keeps equally-timed deletions stable across pages', function () {
    $owner = User::factory()->create();
    $deletedAt = now()->startOfSecond();

    $posts = Post::factory()->published()->for($owner)->count(5)->create();

    foreach ($posts as $post) {
        app(DeletePostAction::class)->handle($owner, $post);
    }

    Post::onlyTrashed()->where('user_id', $owner->id)->update(['deleted_at' => $deletedAt]);

    $expected = $posts->pluck('id')->reverse()->values();

    $query = app(RecentlyDeletedPostsQuery::class);
    $pageOne = $query->forOwner($owner, perPage: 2);
    $pageTwo = Post::onlyTrashed()
        ->where('user_id', $owner->id)
        ->where('status', PostStatus::Deleted)
        ->orderByDesc('deleted_at')
        ->orderByDesc('id')
        ->forPage(2, 2)
        ->pluck('id');

    expect($pageOne->pluck('id')->all())->toBe($expected->slice(0, 2)->values()->all())
        ->and($pageTwo->all())->toBe($expected->slice(2, 2)->values()->all());
});
