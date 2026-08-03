<?php

use App\Enums\PostStatus;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Queries\Feed\FeedQuery;
use Database\Seeders\DemoDatabaseSeeder;
use Database\Seeders\DemoPublishedPostsSeeder;
use Database\Seeders\DemoTagsSeeder;
use Database\Seeders\DemoUsersSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

it('seeds published demo posts', function () {
    $this->seed(DemoUsersSeeder::class);
    $this->seed(DemoTagsSeeder::class);
    $this->seed(DemoPublishedPostsSeeder::class);

    expect(Post::query()->where('status', PostStatus::Published)->count())
        ->toBe(14);
});

it('seeds published posts with authors and tags', function () {
    $this->seed(DemoDatabaseSeeder::class);

    $post = Post::query()->where('status', PostStatus::Published)->firstOrFail();

    expect($post->user)->not->toBeNull();
    expect($post->tags()->count())->toBeGreaterThan(0);
});

it('seeds both categorized and uncategorized published posts', function () {
    $this->seed(DemoDatabaseSeeder::class);

    $publishedPosts = Post::query()->where('status', PostStatus::Published);

    expect((clone $publishedPosts)->whereNotNull('category_id')->exists())->toBeTrue()
        ->and((clone $publishedPosts)->whereNull('category_id')->exists())->toBeTrue();
});

it('creates public media files for every seeded post image path', function () {
    Storage::fake('public');

    $this->seed(DemoDatabaseSeeder::class);

    $queryCount = 0;

    DB::listen(function () use (&$queryCount): void {
        $queryCount++;
    });

    $imagePaths = Post::query()
        ->whereNotNull('image_asset_id')
        ->with('imageAsset')
        ->get()
        ->pluck('imageAsset.path');

    // One query for posts, one for the eager-loaded imageAsset batch — this
    // must fail if with('imageAsset') is ever dropped and imageAsset falls
    // back to lazy-loading once per post.
    expect($queryCount)->toBeLessThanOrEqual(2);

    expect($imagePaths)->toHaveCount(19);

    foreach ($imagePaths as $imagePath) {
        Storage::disk('public')->assertExists($imagePath);
    }
});

it('seeded published posts are visible through feed query', function () {
    $this->seed(DemoDatabaseSeeder::class);

    $posts = app(FeedQuery::class)->get();

    expect($posts)->not->toBeEmpty();
    expect($posts->every(fn (Post $post) => $post->status === PostStatus::Published))->toBeTrue();
});

it('reuses the same media assets instead of accumulating rows when re-seeded', function () {
    Storage::fake('public');

    $this->seed(DemoUsersSeeder::class);
    $this->seed(DemoTagsSeeder::class);
    $this->seed(DemoPublishedPostsSeeder::class);

    $assetIdsAfterFirstRun = Post::query()->whereNotNull('image_asset_id')->pluck('image_asset_id')->sort()->values();
    $mediaAssetCountAfterFirstRun = MediaAsset::query()->count();

    $this->seed(DemoPublishedPostsSeeder::class);

    $assetIdsAfterSecondRun = Post::query()->whereNotNull('image_asset_id')->pluck('image_asset_id')->sort()->values();

    expect(MediaAsset::query()->count())->toBe($mediaAssetCountAfterFirstRun)
        ->and($assetIdsAfterSecondRun)->toEqual($assetIdsAfterFirstRun);
});

it('seeds generic demo posts without food-specific titles', function () {
    $this->seed(DemoDatabaseSeeder::class);

    $content = strtolower(Post::query()->pluck('title')->implode(' '));

    expect($content)->not->toContain('pasta');
    expect($content)->not->toContain('sushi');
    expect($content)->not->toContain('tacos');
});
