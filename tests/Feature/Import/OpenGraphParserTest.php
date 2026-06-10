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
