<?php

use App\Models\Post;
use App\Support\Sharing\PostShareMetadata;
use App\Support\Sharing\ShareUrlBuilder;
use Illuminate\Support\Facades\Blade;

it('renders reddit share link with correct url', function () {
    config(['app.url' => 'https://rateguru.test']);
    $post = Post::factory()->published()->create(['title' => 'Reddit Test Post']);

    $metadata = app(PostShareMetadata::class)->forPost($post);
    $url = app(ShareUrlBuilder::class)->build('reddit', $metadata);

    $html = Blade::render(
        '<x-share.provider-link provider="reddit" :url="$url" label="Share on Reddit" />',
        ['url' => $url]
    );

    expect($html)->toContain('data-testid="share-reddit"');
    expect($html)->toContain('reddit.com/submit');
    expect($html)->toContain('target="_blank"');
});

it('renders email share link', function () {
    config(['app.url' => 'https://rateguru.test']);
    $post = Post::factory()->published()->create(['title' => 'Email Test Post']);

    $metadata = app(PostShareMetadata::class)->forPost($post);
    $url = app(ShareUrlBuilder::class)->build('email', $metadata);

    $html = Blade::render(
        '<x-share.provider-link provider="email" :url="$url" label="Share via Email" />',
        ['url' => $url]
    );

    expect($html)->toContain('data-testid="share-email"');
    expect($html)->toContain('mailto:');
});

it('renders pinterest share link when image exists', function () {
    config(['app.url' => 'https://rateguru.test']);
    $post = Post::factory()->published()->create([
        'image_url' => 'https://rateguru.test/storage/posts/test.jpg',
    ]);

    $metadata = app(PostShareMetadata::class)->forPost($post);
    $url = app(ShareUrlBuilder::class)->build('pinterest', $metadata);

    $html = Blade::render(
        '<x-share.provider-link provider="pinterest" :url="$url" label="Share on Pinterest" />',
        ['url' => $url]
    );

    expect($html)->toContain('data-testid="share-pinterest"');
    expect($html)->toContain('pinterest.com/pin/create/button');
});

it('does not render pinterest link when post has no image', function () {
    $post = Post::factory()->published()->create([
        'image_path' => null,
        'image_url' => null,
    ]);

    $metadata = app(PostShareMetadata::class)->forPost($post);
    $url = app(ShareUrlBuilder::class)->build('pinterest', $metadata);

    expect($url)->toBeNull();
});
