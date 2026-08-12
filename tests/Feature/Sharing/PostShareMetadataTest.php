<?php

use App\Models\Post;
use App\Models\ProjectSettings;
use App\Support\Settings\ProjectSettingsManager;
use App\Support\Sharing\PostShareMetadata;
use Illuminate\Support\Facades\Storage;

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
    // fake() (not a real, unfaked disk) both isolates the write from the
    // real filesystem and satisfies PostImagePresenter::openGraph()'s
    // MediaStorage::exists() check — the url/visibility/driver config must
    // be passed directly into fake() since it otherwise drops any config
    // already set on the disk (it only ever carries over `throw`).
    Storage::fake('cdn_test', [
        'driver' => 'local',
        'url' => 'https://rateguru.test/storage',
        'visibility' => 'public',
    ]);

    $post = Post::factory()->published()->withImage(path: 'posts/test.jpg', disk: 'cdn_test')->create();
    Storage::disk('cdn_test')->put('posts/test.jpg', 'test-bytes');

    $metadata = app(PostShareMetadata::class)->forPost($post);

    expect($metadata->imageUrl)->toStartWith('https://rateguru.test');
    expect($metadata->imageUrl)->toContain('/storage/posts/test.jpg');
});

it('returns the asset disk url as-is when it already points off-origin', function () {
    Storage::fake('cdn_test', [
        'driver' => 'local',
        'url' => 'https://cdn.example.com',
        'visibility' => 'public',
    ]);

    $post = Post::factory()->published()->withImage(path: 'image.jpg', disk: 'cdn_test')->create();
    Storage::disk('cdn_test')->put('image.jpg', 'test-bytes');

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
