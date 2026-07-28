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
        'image_path' => null,
        'image_url' => null,
    ]);

    $metadata = app(PostShareMetadata::class)->forPost($post);

    expect($metadata->imageUrl)
        ->toBe('https://rateguru.test/images/og/rateguru-post-placeholder.png');
});

it('returns absolute image url when post has relative image url', function () {
    config(['app.url' => 'https://rateguru.test']);

    $post = Post::factory()->published()->create([
        'image_url' => '/storage/posts/test.jpg',
    ]);

    $metadata = app(PostShareMetadata::class)->forPost($post);

    expect($metadata->imageUrl)->toStartWith('https://rateguru.test');
    expect($metadata->imageUrl)->toContain('/storage/posts/test.jpg');
});

it('returns absolute image url when post has absolute image url', function () {
    $post = Post::factory()->published()->create([
        'image_url' => 'https://cdn.example.com/image.jpg',
    ]);

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
