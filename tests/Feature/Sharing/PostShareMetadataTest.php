<?php

use App\Models\Post;
use App\Support\Sharing\PostShareMetadata;

it('builds post share metadata', function () {
    config(['app.url' => 'https://rateguru.test']);

    $post = Post::factory()->published()->create([
        'title' => 'Share Test Post',
        'description' => 'Share description.',
    ]);

    $metadata = app(PostShareMetadata::class)->forPost($post);

    expect($metadata->title)->toContain('Share Test Post');
    expect($metadata->description)->toContain('Share description');
    expect($metadata->url)->toContain('/posts/');
    expect($metadata->url)->toStartWith('https://rateguru.test');
});

it('returns null image url when post has no image', function () {
    $post = Post::factory()->published()->create([
        'image_path' => null,
        'image_url' => null,
    ]);

    $metadata = app(PostShareMetadata::class)->forPost($post);

    expect($metadata->imageUrl)->toBeNull();
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
    $post = Post::factory()->published()->create([
        'description' => null,
    ]);

    $metadata = app(PostShareMetadata::class)->forPost($post);

    expect($metadata->description)->not->toBeEmpty();
});

it('canonical url is absolute', function () {
    config(['app.url' => 'https://rateguru.test']);

    $post = Post::factory()->published()->create();

    $metadata = app(PostShareMetadata::class)->forPost($post);

    expect($metadata->url)->toStartWith('https://');
});
