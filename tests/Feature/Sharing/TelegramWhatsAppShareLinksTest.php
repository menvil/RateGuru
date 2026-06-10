<?php

use App\Models\Post;
use App\Support\Sharing\PostShareMetadata;
use App\Support\Sharing\ShareUrlBuilder;
use Illuminate\Support\Facades\Blade;

it('renders telegram share link with correct url', function () {
    config(['app.url' => 'https://rateguru.test']);
    $post = Post::factory()->published()->create(['title' => 'Telegram Test Post']);

    $metadata = app(PostShareMetadata::class)->forPost($post);
    $url = app(ShareUrlBuilder::class)->build('telegram', $metadata);

    $html = Blade::render(
        '<x-share.provider-link provider="telegram" :url="$url" label="Share on Telegram" />',
        ['url' => $url]
    );

    expect($html)->toContain('data-testid="share-telegram"');
    expect($html)->toContain('t.me');
    expect($html)->toContain('window.open');
});

it('renders whatsapp share link with correct url', function () {
    config(['app.url' => 'https://rateguru.test']);
    $post = Post::factory()->published()->create(['title' => 'WhatsApp Test Post']);

    $metadata = app(PostShareMetadata::class)->forPost($post);
    $url = app(ShareUrlBuilder::class)->build('whatsapp', $metadata);

    $html = Blade::render(
        '<x-share.provider-link provider="whatsapp" :url="$url" label="Share on WhatsApp" />',
        ['url' => $url]
    );

    expect($html)->toContain('data-testid="share-whatsapp"');
    expect($html)->toContain('wa.me');
    expect($html)->toContain('window.open');
});
