<?php

use App\Support\Urls\ApiBaseUrl;

it('lets an API key travel only over https, or in plain http to this machine', function (string $url, bool $safe) {
    expect(ApiBaseUrl::carriesKeySafely($url))->toBe($safe);
})->with([
    'https' => ['https://api.openai.com/v1', true],
    'http to localhost' => ['http://localhost:8138/v1', true],
    'http to 127.0.0.1' => ['http://127.0.0.1:8138/v1', true],
    'http to ::1' => ['http://[::1]:8138/v1', true],
    'http across the network' => ['http://api.openai.com/v1', false],
    'http to a private address' => ['http://10.0.0.5/v1', false],
    'http to a name that starts like localhost' => ['http://localhost.attacker.example/v1', false],
    'another scheme' => ['ftp://api.openai.com/v1', false],
    'no scheme' => ['api.openai.com/v1', false],
]);
