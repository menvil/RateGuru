<?php

use App\Enums\PostImageContext;
use App\Models\Post;
use App\Models\User;
use App\Queries\Feed\FeedQuery;
use App\Support\Media\AvatarUrlResolver;
use App\Support\Media\PostImagePresenter;
use Illuminate\Support\Facades\DB;

it('does not n+1 query post image assets or author avatar assets', function () {
    $authors = User::factory()->withAvatar()->count(5)->create();

    foreach ($authors as $author) {
        Post::factory()->published()->for($author)->withImage()->create();
    }

    $queryCount = 0;

    DB::listen(function () use (&$queryCount): void {
        $queryCount++;
    });

    $posts = app(FeedQuery::class)->get();

    foreach ($posts as $post) {
        // Access both accessors, plus responsive(), exactly as feed
        // rendering does — if any relation isn't eager-loaded, this
        // triggers one query per post.
        $post->public_image_url;
        $post->user->resolved_avatar_url;
        app(PostImagePresenter::class)->responsive($post, PostImageContext::Feed);
        app(AvatarUrlResolver::class)->responsive($post->user);
    }

    // One query for posts, one for tags, one for the imageAsset batch, one
    // for its variants batch, one for the user batch, one for the
    // avatarAsset batch, one for its variants batch — a small constant, not
    // one per post.
    expect($queryCount)->toBeLessThanOrEqual(8);
});
