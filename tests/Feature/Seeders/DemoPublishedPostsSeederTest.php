<?php

use App\Enums\PostStatus;
use App\Models\Post;
use App\Queries\Feed\FeedQuery;
use Database\Seeders\DemoDatabaseSeeder;
use Database\Seeders\DemoPublishedPostsSeeder;
use Database\Seeders\DemoTagsSeeder;
use Database\Seeders\DemoUsersSeeder;
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

    $imagePaths = Post::query()
        ->whereNotNull('image_path')
        ->pluck('image_path');

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

it('seeds generic demo posts without food-specific titles', function () {
    $this->seed(DemoDatabaseSeeder::class);

    $content = strtolower(Post::query()->pluck('title')->implode(' '));

    expect($content)->not->toContain('pasta');
    expect($content)->not->toContain('sushi');
    expect($content)->not->toContain('tacos');
});
