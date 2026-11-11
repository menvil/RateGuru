<?php

use App\Support\Urls\DisplayHost;

it('reads a URL as the site it belongs to', function (string $url, string $host) {
    expect(DisplayHost::of($url))->toBe($host);
})->with([
    'a game page' => ['https://example.com/games/trust-your-taste', 'example.com'],
    'a leading www' => ['https://www.Example.com/games/trust-your-taste/v1/oracle', 'example.com'],
    'a port' => ['http://localhost:8000/games/trust-your-taste', 'localhost:8000'],
    'a subdomain' => ['https://staging.example.com/', 'staging.example.com'],
    'no host' => ['/games/trust-your-taste', ''],
]);
