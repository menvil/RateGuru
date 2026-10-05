<?php

use App\Data\Seo\OpenGraphImageData;

function ogImageData(string $url): OpenGraphImageData
{
    return new OpenGraphImageData(
        url: $url,
        mimeType: 'image/jpeg',
        width: 1200,
        height: 630,
        alt: 'A dish',
    );
}

it('reports a secure URL whatever case the scheme is written in', function (string $url) {
    // parse_url does not normalise the scheme, and a scheme is case-insensitive
    // (RFC 3986 §3.1). A case-sensitive comparison silently drops
    // og:image:secure_url for a URL that is perfectly secure.
    expect(ogImageData($url)->secureUrl())->toBe($url);
})->with([
    'lowercase' => ['https://rateguru.test/storage/dish.jpg'],
    'uppercase' => ['HTTPS://rateguru.test/storage/dish.jpg'],
    'mixed case' => ['HttPs://rateguru.test/storage/dish.jpg'],
]);

it('reports no secure URL for anything that is not HTTPS', function (string $url) {
    expect(ogImageData($url)->secureUrl())->toBeNull();
})->with([
    'http' => ['http://rateguru.test/storage/dish.jpg'],
    'uppercase http' => ['HTTP://rateguru.test/storage/dish.jpg'],
    'protocol-relative' => ['//rateguru.test/storage/dish.jpg'],
    'path only' => ['/storage/dish.jpg'],
]);
