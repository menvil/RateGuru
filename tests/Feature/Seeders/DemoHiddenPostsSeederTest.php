<?php

use App\Enums\PostStatus;
use App\Models\MediaAsset;
use App\Models\Post;
use Database\Seeders\DemoDatabaseSeeder;
use Database\Seeders\DemoHiddenPostsSeeder;
use Database\Seeders\DemoTagsSeeder;
use Database\Seeders\DemoUsersSeeder;
use Illuminate\Support\Facades\Storage;

it('seeds hidden demo posts', function () {
    $this->seed(DemoUsersSeeder::class);
    $this->seed(DemoTagsSeeder::class);
    $this->seed(DemoHiddenPostsSeeder::class);

    expect(Post::query()->where('status', PostStatus::Hidden)->count())
        ->toBe(2);
});

it('seeds hidden posts with authors and tags', function () {
    $this->seed(DemoDatabaseSeeder::class);

    $post = Post::query()->where('status', PostStatus::Hidden)->firstOrFail();

    expect($post->user)->not->toBeNull();
    expect($post->tags()->count())->toBeGreaterThan(0);
});

it('seeded hidden posts are not visible in public feed', function () {
    $this->seed(DemoDatabaseSeeder::class);

    $hiddenTitle = Post::query()
        ->where('status', PostStatus::Hidden)
        ->firstOrFail()
        ->title;

    $this->get(route('feed'))->assertDontSee($hiddenTitle);
});

it('reuses the same media assets instead of accumulating rows when re-seeded', function () {
    Storage::fake('public');

    $this->seed(DemoUsersSeeder::class);
    $this->seed(DemoTagsSeeder::class);
    $this->seed(DemoHiddenPostsSeeder::class);

    $postCountAfterFirstRun = Post::query()->where('status', PostStatus::Hidden)->count();
    $mediaAssetCountAfterFirstRun = MediaAsset::query()->count();

    $this->seed(DemoHiddenPostsSeeder::class);

    expect(Post::query()->where('status', PostStatus::Hidden)->count())->toBe($postCountAfterFirstRun)
        ->and(MediaAsset::query()->count())->toBe($mediaAssetCountAfterFirstRun);
});
