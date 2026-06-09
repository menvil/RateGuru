<?php

use App\Support\Sharing\ShareMetadata;
use App\Support\Sharing\ShareUrlBuilder;

function makeMetadata(bool $withImage = true): ShareMetadata
{
    return new ShareMetadata(
        title: 'Test Post Title',
        description: 'Test description text.',
        url: 'https://example.test/posts/1',
        imageUrl: $withImage ? 'https://example.test/image.jpg' : null,
        siteName: 'RateGuru',
    );
}

it('builds facebook share url', function () {
    $url = app(ShareUrlBuilder::class)->build('facebook', makeMetadata());

    expect($url)->toContain('facebook.com/sharer');
    expect($url)->toContain(urlencode('https://example.test/posts/1'));
});

it('builds x share url', function () {
    $url = app(ShareUrlBuilder::class)->build('x', makeMetadata());

    expect($url)->toContain('twitter.com/intent/tweet');
    expect($url)->toContain('example.test');
});

it('builds telegram share url', function () {
    $url = app(ShareUrlBuilder::class)->build('telegram', makeMetadata());

    expect($url)->toContain('t.me/share/url');
    expect($url)->toContain('example.test');
});

it('builds whatsapp share url', function () {
    $url = app(ShareUrlBuilder::class)->build('whatsapp', makeMetadata());

    expect($url)->toContain('wa.me');
    expect($url)->toContain('example.test');
});

it('builds reddit share url', function () {
    $url = app(ShareUrlBuilder::class)->build('reddit', makeMetadata());

    expect($url)->toContain('reddit.com/submit');
    expect($url)->toContain('example.test');
});

it('builds pinterest share url when image exists', function () {
    $url = app(ShareUrlBuilder::class)->build('pinterest', makeMetadata(withImage: true));

    expect($url)->toContain('pinterest.com/pin/create/button');
    expect($url)->toContain('example.test');
});

it('does not build pinterest share url without image', function () {
    $url = app(ShareUrlBuilder::class)->build('pinterest', makeMetadata(withImage: false));

    expect($url)->toBeNull();
});

it('builds email share url', function () {
    $url = app(ShareUrlBuilder::class)->build('email', makeMetadata());

    expect($url)->toStartWith('mailto:');
    expect($url)->toContain('subject');
    expect($url)->toContain('body');
});

it('returns null for copy_link provider', function () {
    $url = app(ShareUrlBuilder::class)->build('copy_link', makeMetadata());

    expect($url)->toBeNull();
});

it('returns null for native provider', function () {
    $url = app(ShareUrlBuilder::class)->build('native', makeMetadata());

    expect($url)->toBeNull();
});
