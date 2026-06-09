<?php

use App\Models\Post;
use App\Support\Sharing\PostShareMetadata;
use App\Support\Sharing\ShareUrlBuilder;
use Illuminate\Support\Facades\Blade;

it('renders facebook share link component with correct url', function () {
    config(['app.url' => 'https://rateguru.test']);
    $post = Post::factory()->published()->create(['title' => 'Facebook Test Post']);

    $metadata = app(PostShareMetadata::class)->forPost($post);
    $fbUrl = app(ShareUrlBuilder::class)->build('facebook', $metadata);

    $html = Blade::render(
        '<x-share.provider-link provider="facebook" :url="$url" label="Share on Facebook" />',
        ['url' => $fbUrl]
    );

    expect($html)->toContain('data-testid="share-facebook"');
    expect($html)->toContain('facebook.com/sharer');
    expect($html)->toContain('target="_blank"');
    expect($html)->toContain('rel="noopener noreferrer"');
});

it('renders x share link component with correct url', function () {
    config(['app.url' => 'https://rateguru.test']);
    $post = Post::factory()->published()->create(['title' => 'X Test Post']);

    $metadata = app(PostShareMetadata::class)->forPost($post);
    $xUrl = app(ShareUrlBuilder::class)->build('x', $metadata);

    $html = Blade::render(
        '<x-share.provider-link provider="x" :url="$url" label="Share on X" />',
        ['url' => $xUrl]
    );

    expect($html)->toContain('data-testid="share-x"');
    expect($html)->toContain('twitter.com/intent/tweet');
    expect($html)->toContain('target="_blank"');
});
