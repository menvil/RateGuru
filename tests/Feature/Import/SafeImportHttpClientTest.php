<?php

use App\Exceptions\Import\ImportFetchException;
use App\Exceptions\Import\UnsafeImportUrlException;
use App\Support\Import\SafeImportHttpClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

it('fetches import url with configured timeout and limits', function () {
    Http::fake([
        'example.com/*' => Http::response('<html></html>', 200, [
            'Content-Type' => 'text/html',
        ]),
    ]);

    $response = app(SafeImportHttpClient::class)->get('https://example.com/page');

    expect($response->status())->toBe(200);
});

it('rejects unsafe urls before fetching', function () {
    app(SafeImportHttpClient::class)->get('http://localhost/test');
})->throws(UnsafeImportUrlException::class);

it('throws import fetch exception on request failure', function () {
    Http::fake([
        'example.com/*' => Http::response('', 500),
    ]);

    app(SafeImportHttpClient::class)->get('https://example.com/page');
})->throws(ImportFetchException::class);

it('throws import fetch exception on connection error', function () {
    Http::fake(fn () => throw new ConnectionException('timeout'));

    app(SafeImportHttpClient::class)->get('https://example.com/page');
})->throws(ImportFetchException::class);

it('rejects response body exceeding max html bytes', function () {
    $oversized = str_repeat('x', config('import.max_html_bytes') + 1);

    Http::fake([
        'example.com/*' => Http::response($oversized, 200, [
            'Content-Type' => 'text/html',
        ]),
    ]);

    app(SafeImportHttpClient::class)->get('https://example.com/page');
})->throws(ImportFetchException::class);
