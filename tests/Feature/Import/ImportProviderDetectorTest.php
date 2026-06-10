<?php

use App\Support\Import\ImportProviderDetector;

it('detects import providers from urls', function () {
    $detector = app(ImportProviderDetector::class);

    expect($detector->detect('https://example.com/image.jpg'))->toBe('direct_image');
    expect($detector->detect('https://www.instagram.com/p/abc'))->toBe('instagram');
    expect($detector->detect('https://www.facebook.com/photo.php?id=1'))->toBe('facebook');
    expect($detector->detect('https://x.com/user/status/1'))->toBe('x');
    expect($detector->detect('https://example.com/page'))->toBe('open_graph');
});

it('detects twitter.com as x provider', function () {
    expect(app(ImportProviderDetector::class)->detect('https://twitter.com/user/status/1'))->toBe('x');
});

it('detects pinterest as provider', function () {
    expect(app(ImportProviderDetector::class)->detect('https://www.pinterest.com/pin/123456'))->toBe('pinterest');
});

it('detects direct image by common image extensions', function () {
    $detector = app(ImportProviderDetector::class);

    expect($detector->detect('https://example.com/photo.png'))->toBe('direct_image');
    expect($detector->detect('https://example.com/photo.webp'))->toBe('direct_image');
    expect($detector->detect('https://cdn.example.com/images/banner.jpeg'))->toBe('direct_image');
});

it('defaults unknown urls to open_graph', function () {
    expect(app(ImportProviderDetector::class)->detect('https://mysite.com/article/123'))->toBe('open_graph');
});
