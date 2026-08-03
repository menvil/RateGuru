<?php

use App\Models\Post;
use App\Models\ProjectSettings;
use App\Support\Settings\ProjectSettingsManager;
use App\Support\Sharing\PostShareMetadata;

it('builds post share metadata', function () {
    config(['app.url' => 'https://rateguru.test']);

    $post = Post::factory()->published()->create([
        'title' => 'Share Test Post',
        'description' => 'Share description.',
    ]);

    $metadata = app(PostShareMetadata::class)->forPost($post);

    expect($metadata->title)->toBe('Share Test Post');
    expect($metadata->shareText)->toBe('Share Test Post');
    expect($metadata->description)->toContain('Share description');
    expect($metadata->url)->toContain('/posts/');
    expect($metadata->url)->toStartWith('https://rateguru.test');
});

it('uses the raster fallback image when post has no image', function () {
    config(['app.url' => 'https://rateguru.test']);

    $post = Post::factory()->published()->create([
        'image_asset_id' => null,
    ]);

    $metadata = app(PostShareMetadata::class)->forPost($post);

    expect($metadata->imageUrl)
        ->toBe('https://rateguru.test/images/og/rateguru-post-placeholder.png');
});

it('returns an absolute image url resolved through the asset disk', function () {
    // The image's absolute URL comes from Storage::disk($asset->disk)->url(),
    // resolved against the disk's own url config, not config('app.url').
    config(['filesystems.disks.cdn_test' => [
        'driver' => 'local',
        'root' => storage_path('app/cdn_test'),
        'url' => 'https://rateguru.test/storage',
        'visibility' => 'public',
    ]]);

    $post = Post::factory()->published()->withImage(path: 'posts/test.jpg', disk: 'cdn_test')->create();

    $metadata = app(PostShareMetadata::class)->forPost($post);

    expect($metadata->imageUrl)->toStartWith('https://rateguru.test');
    expect($metadata->imageUrl)->toContain('/storage/posts/test.jpg');
});

it('returns the asset disk url as-is when it already points off-origin', function () {
    config(['filesystems.disks.cdn_test' => [
        'driver' => 'local',
        'root' => storage_path('app/cdn_test'),
        'url' => 'https://cdn.example.com',
        'visibility' => 'public',
    ]]);

    $post = Post::factory()->published()->withImage(path: 'image.jpg', disk: 'cdn_test')->create();

    $metadata = app(PostShareMetadata::class)->forPost($post);

    expect($metadata->imageUrl)->toBe('https://cdn.example.com/image.jpg');
});

it('uses fallback description when post has no description', function () {
    app()->setLocale('ru');

    ProjectSettings::factory()->create([
        'site_name' => 'RateGuru',
        'site_name_translations' => ['ru' => 'РейтГуру'],
    ]);
    app(ProjectSettingsManager::class)->flush();

    $post = Post::factory()->published()->create([
        'description' => null,
    ]);

    $metadata = app(PostShareMetadata::class)->forPost($post);

    expect($metadata->description)->toBe('Посмотрите и оцените этот пост на сайте РейтГуру.');
    expect($metadata->siteName)->toBe('РейтГуру');
});

it('canonical url is absolute', function () {
    config(['app.url' => 'https://rateguru.test']);

    $post = Post::factory()->published()->create();

    $metadata = app(PostShareMetadata::class)->forPost($post);

    expect($metadata->url)->toStartWith('https://');
});
