<?php

use App\Enums\PostStatus;
use App\Models\MediaAsset;
use App\Models\Post;
use Database\Seeders\DemoPendingPostsSeeder;
use Database\Seeders\DemoTagsSeeder;
use Database\Seeders\DemoUsersSeeder;
use Illuminate\Support\Facades\Storage;

// The seeder writes each post's image to the public disk.
beforeEach(function () {
    Storage::fake('public');
});

it('seeds pending demo posts', function () {
    $this->seed(DemoUsersSeeder::class);
    $this->seed(DemoTagsSeeder::class);
    $this->seed(DemoPendingPostsSeeder::class);

    expect(Post::query()->where('status', PostStatus::Pending)->count())
        ->toBe(3);
});

it('reuses the same media assets instead of accumulating rows when re-seeded', function () {
    $this->seed(DemoUsersSeeder::class);
    $this->seed(DemoTagsSeeder::class);
    $this->seed(DemoPendingPostsSeeder::class);

    $postCountAfterFirstRun = Post::query()->where('status', PostStatus::Pending)->count();
    $mediaAssetCountAfterFirstRun = MediaAsset::query()->count();

    $this->seed(DemoPendingPostsSeeder::class);

    expect(Post::query()->where('status', PostStatus::Pending)->count())->toBe($postCountAfterFirstRun)
        ->and(MediaAsset::query()->count())->toBe($mediaAssetCountAfterFirstRun);
});
