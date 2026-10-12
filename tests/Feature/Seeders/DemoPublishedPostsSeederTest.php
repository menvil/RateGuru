<?php

use App\Enums\PostStatus;
use App\Models\MediaAsset;
use App\Models\Post;
use Database\Seeders\DemoPublishedPostsSeeder;
use Database\Seeders\DemoTagsSeeder;
use Database\Seeders\DemoUsersSeeder;
use Illuminate\Support\Facades\Storage;

// The seeder writes each post's image to the public disk.
beforeEach(function () {
    Storage::fake('public');
});

it('seeds published demo posts', function () {
    $this->seed(DemoUsersSeeder::class);
    $this->seed(DemoTagsSeeder::class);
    $this->seed(DemoPublishedPostsSeeder::class);

    expect(Post::query()->where('status', PostStatus::Published)->count())
        ->toBe(14);
});

it('reuses the same media assets instead of accumulating rows when re-seeded', function () {
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
