<?php

use App\Support\Import\OpenGraphParser;

it('parses open graph metadata from html', function () {
    $html = <<<'HTML'
<html>
<head>
<meta property="og:title" content="OG Title">
<meta property="og:description" content="OG Description">
<meta property="og:image" content="https://example.com/image.jpg">
</head>
</html>
HTML;

    $metadata = app(OpenGraphParser::class)->parse($html, 'https://example.com/page');

    expect($metadata->title)->toBe('OG Title');
    expect($metadata->description)->toBe('OG Description');
    expect($metadata->imageUrl)->toBe('https://example.com/image.jpg');
});

it('falls back to twitter card meta tags', function () {
    $html = <<<'HTML'
<html>
<head>
<meta name="twitter:title" content="Twitter Title">
<meta name="twitter:description" content="Twitter Desc">
<meta name="twitter:image" content="https://example.com/twitter.jpg">
</head>
</html>
HTML;

    $metadata = app(OpenGraphParser::class)->parse($html, 'https://example.com/page');

    expect($metadata->title)->toBe('Twitter Title');
    expect($metadata->imageUrl)->toBe('https://example.com/twitter.jpg');
});

it('falls back to html title tag', function () {
    $html = '<html><head><title>Page Title</title></head></html>';

    $metadata = app(OpenGraphParser::class)->parse($html, 'https://example.com/page');

    expect($metadata->title)->toBe('Page Title');
});

it('falls back to meta description tag', function () {
    $html = '<html><head><meta name="description" content="Meta Desc"></head></html>';

    $metadata = app(OpenGraphParser::class)->parse($html, 'https://example.com/page');

    expect($metadata->description)->toBe('Meta Desc');
});

it('resolves relative open graph image urls', function () {
    $html = '<html><head><meta property="og:image" content="/images/photo.jpg"></head></html>';

    $metadata = app(OpenGraphParser::class)->parse($html, 'https://example.com/page');

    expect($metadata->imageUrl)->toBe('https://example.com/images/photo.jpg');
});

it('og title takes priority over twitter title', function () {
    $html = <<<'HTML'
<html>
<head>
<meta property="og:title" content="OG Title">
<meta name="twitter:title" content="Twitter Title">
</head>
</html>
HTML;

    $metadata = app(OpenGraphParser::class)->parse($html, 'https://example.com/page');

    expect($metadata->title)->toBe('OG Title');
});

it('returns null fields when no meta found', function () {
    $metadata = app(OpenGraphParser::class)->parse('<html></html>', 'https://example.com/page');

    expect($metadata->title)->toBeNull();
    expect($metadata->imageUrl)->toBeNull();
});

it('resolves protocol-relative image urls using page scheme', function () {
    $html = '<html><head><meta property="og:image" content="//cdn.example.com/image.jpg"></head></html>';

    $metadata = app(OpenGraphParser::class)->parse($html, 'https://example.com/page');

    expect($metadata->imageUrl)->toBe('https://cdn.example.com/image.jpg');
});

it('preserves non-default port when resolving relative image urls', function () {
    $html = '<html><head><meta property="og:image" content="/image.jpg"></head></html>';

    $metadata = app(OpenGraphParser::class)->parse($html, 'https://example.com:8080/page');

    expect($metadata->imageUrl)->toBe('https://example.com:8080/image.jpg');
});

it('reads an image url without the whitespace around it', function () {
    $html = "<html><head><meta property=\"og:image\" content=\"  https://cdn.example.com/photo.jpg\n\"></head></html>";

    $metadata = app(OpenGraphParser::class)->parse($html, 'https://example.com/page');

    expect($metadata->imageUrl)->toBe('https://cdn.example.com/photo.jpg');
});

it('names every image the page gives, in order of preference, each resolved once', function () {
    $html = <<<'HTML'
<html><head>
<meta name="twitter:image" content="/photo.jpg">
<meta property="og:image" content="http://example.com/photo.jpg">
<meta property="og:image:secure_url" content="https://example.com/photo.jpg">
<meta property="og:image" content="https://example.com/second.jpg">
</head></html>
HTML;

    $metadata = app(OpenGraphParser::class)->parse($html, 'https://example.com/page');

    // Every og:image as the page orders them, then the secure ones, then Twitter's.
    expect($metadata->imageCandidates)->toBe(['http://example.com/photo.jpg', 'https://example.com/second.jpg', 'https://example.com/photo.jpg'])
        ->and($metadata->imageUrl)->toBe('http://example.com/photo.jpg');
});

it('names no more images than the import tries, the preferred ones first', function () {
    $tags = implode('', array_map(fn (int $n): string => "<meta property=\"og:image\" content=\"https://img{$n}.example.com/photo.jpg\">", range(1, 50)));

    $metadata = app(OpenGraphParser::class)->parse("<html><head>{$tags}</head></html>", 'https://example.com/page');

    expect($metadata->imageCandidates)->toHaveCount(OpenGraphParser::MOST_IMAGE_CANDIDATES)
        ->and($metadata->imageCandidates[0])->toBe('https://img1.example.com/photo.jpg');
});
