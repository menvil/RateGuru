<?php

use App\Enums\ShareProvider;
use App\Support\Sharing\ShareMetadata;
use App\Support\Sharing\ShareUrlBuilder;

function providerMetadata(bool $withImage = true): ShareMetadata
{
    return new ShareMetadata(
        title: 'My Post Title With Special & Chars',
        description: 'A full description of the post.',
        url: 'https://rateguru.test/posts/42',
        imageUrl: $withImage ? 'https://rateguru.test/storage/posts/img.jpg' : null,
        siteName: 'RateGuru',
    );
}

it('url-encodes the post url in facebook share url', function () {
    $url = app(ShareUrlBuilder::class)->build(ShareProvider::Facebook, providerMetadata());

    expect($url)->toContain(urlencode('https://rateguru.test/posts/42'));
});

it('x share url contains encoded url and title', function () {
    $url = app(ShareUrlBuilder::class)->build(ShareProvider::X, providerMetadata());

    expect($url)->toStartWith('https://twitter.com/intent/tweet');
    expect($url)->toContain('rateguru.test');
    expect($url)->toContain('My+Post+Title');
});

it('telegram share url contains encoded url and text', function () {
    $url = app(ShareUrlBuilder::class)->build(ShareProvider::Telegram, providerMetadata());

    expect($url)->toStartWith('https://t.me/share/url');
    expect($url)->toContain('rateguru.test');
});

it('whatsapp share url contains encoded text and url', function () {
    $url = app(ShareUrlBuilder::class)->build(ShareProvider::WhatsApp, providerMetadata());

    expect($url)->toStartWith('https://wa.me/');
    expect($url)->toContain('rateguru.test');
});

it('reddit share url contains encoded url and title', function () {
    $url = app(ShareUrlBuilder::class)->build(ShareProvider::Reddit, providerMetadata());

    expect($url)->toStartWith('https://www.reddit.com/submit');
    expect($url)->toContain('rateguru.test');
    expect($url)->toContain('My+Post+Title');
});

it('pinterest share url contains url media and description', function () {
    $url = app(ShareUrlBuilder::class)->build(ShareProvider::Pinterest, providerMetadata(withImage: true));

    expect($url)->toStartWith('https://pinterest.com/pin/create/button');
    expect($url)->toContain('rateguru.test');
    expect($url)->toContain('media');
});

it('pinterest returns null when image is missing', function () {
    expect(app(ShareUrlBuilder::class)->build(ShareProvider::Pinterest, providerMetadata(withImage: false)))->toBeNull();
});

it('email mailto contains encoded subject and body', function () {
    $url = app(ShareUrlBuilder::class)->build(ShareProvider::Email, providerMetadata());

    expect($url)->toStartWith('mailto:');
    expect($url)->toContain('subject=');
    expect($url)->toContain('body=');
    expect($url)->toContain('rateguru.test');
});

it('all url providers return non-null strings', function () {
    $metadata = providerMetadata(withImage: true);

    foreach (ShareProvider::urlProviders() as $provider) {
        $url = app(ShareUrlBuilder::class)->build($provider, $metadata);
        expect($url)->toBeString("Provider {$provider->value} should return a URL");
    }
});

it('throws value error for invalid provider string', function () {
    expect(fn () => app(ShareUrlBuilder::class)->build('invalid_provider', providerMetadata()))
        ->toThrow(ValueError::class);
});
