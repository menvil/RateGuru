<?php

use App\Enums\PostStatus;
use App\Models\MediaAsset;
use App\Models\Post;
use Database\Seeders\DemoHiddenPostsSeeder;
use Database\Seeders\DemoTagsSeeder;
use Database\Seeders\DemoUsersSeeder;
use Illuminate\Support\Facades\Storage;

// The seeder writes each post's image to the public disk.
beforeEach(function () {
    Storage::fake('public');
});

it('seeds hidden demo posts', function () {
    $this->seed(DemoUsersSeeder::class);
    $this->seed(DemoTagsSeeder::class);
    $this->seed(DemoHiddenPostsSeeder::class);

    expect(Post::query()->where('status', PostStatus::Hidden)->count())
        ->toBe(2);
});

it('reuses the same media assets instead of accumulating rows when re-seeded', function () {
    $this->seed(DemoUsersSeeder::class);
    $this->seed(DemoTagsSeeder::class);
    $this->seed(DemoHiddenPostsSeeder::class);

    $postCountAfterFirstRun = Post::query()->where('status', PostStatus::Hidden)->count();
    $mediaAssetCountAfterFirstRun = MediaAsset::query()->count();

    $this->seed(DemoHiddenPostsSeeder::class);

    expect(Post::query()->where('status', PostStatus::Hidden)->count())->toBe($postCountAfterFirstRun)
        ->and(MediaAsset::query()->count())->toBe($mediaAssetCountAfterFirstRun);
});
