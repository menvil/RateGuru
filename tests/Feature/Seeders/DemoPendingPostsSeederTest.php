<?php

use App\Enums\PostStatus;
use App\Models\MediaAsset;
use App\Models\Post;
use Database\Seeders\DemoDatabaseSeeder;
use Database\Seeders\DemoPendingPostsSeeder;
use Database\Seeders\DemoTagsSeeder;
use Database\Seeders\DemoUsersSeeder;
use Illuminate\Support\Facades\Storage;

it('seeds pending demo posts', function () {
    $this->seed(DemoUsersSeeder::class);
    $this->seed(DemoTagsSeeder::class);
    $this->seed(DemoPendingPostsSeeder::class);

    expect(Post::query()->where('status', PostStatus::Pending)->count())
        ->toBe(3);
});

it('seeds pending posts with authors and tags', function () {
    $this->seed(DemoDatabaseSeeder::class);

    $post = Post::query()->where('status', PostStatus::Pending)->firstOrFail();

    expect($post->user)->not->toBeNull();
    expect($post->tags()->count())->toBeGreaterThan(0);
});

it('seeded pending posts are not visible in public feed', function () {
    $this->seed(DemoDatabaseSeeder::class);

    $pendingTitle = Post::query()
        ->where('status', PostStatus::Pending)
        ->firstOrFail()
        ->title;

    $this->get(route('feed'))->assertDontSee($pendingTitle);
});

it('reuses the same media assets instead of accumulating rows when re-seeded', function () {
    Storage::fake('public');

    $this->seed(DemoUsersSeeder::class);
    $this->seed(DemoTagsSeeder::class);
    $this->seed(DemoPendingPostsSeeder::class);

    $postCountAfterFirstRun = Post::query()->where('status', PostStatus::Pending)->count();
    $mediaAssetCountAfterFirstRun = MediaAsset::query()->count();

    $this->seed(DemoPendingPostsSeeder::class);

    expect(Post::query()->where('status', PostStatus::Pending)->count())->toBe($postCountAfterFirstRun)
        ->and(MediaAsset::query()->count())->toBe($mediaAssetCountAfterFirstRun);
});
