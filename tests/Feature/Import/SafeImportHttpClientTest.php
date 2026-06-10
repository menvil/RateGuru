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
    app(SafeImportHttpClient::class)->get('https://localhost/test');
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

it('follows redirect and returns final response', function () {
    Http::fake([
        'https://example.com/original' => Http::response('', 302, ['Location' => 'https://example.com/final']),
        'https://example.com/final' => Http::response('<html>final</html>', 200, ['Content-Type' => 'text/html']),
    ]);

    $response = app(SafeImportHttpClient::class)->get('https://example.com/original');

    expect($response->status())->toBe(200);
    expect($response->body())->toContain('final');
});

it('rejects redirect to private ip address', function () {
    Http::fake([
        'https://example.com/*' => Http::response('', 302, ['Location' => 'http://192.168.1.1/secret']),
    ]);

    app(SafeImportHttpClient::class)->get('https://example.com/page');
})->throws(UnsafeImportUrlException::class);

it('rejects too many redirects', function () {
    Http::fake([
        'https://example.com/*' => Http::response('', 302, ['Location' => 'https://example.com/loop']),
    ]);

    app(SafeImportHttpClient::class)->get('https://example.com/start');
})->throws(ImportFetchException::class);

it('uses custom max bytes when provided', function () {
    $oversized = str_repeat('x', 100);

    Http::fake([
        'example.com/*' => Http::response($oversized, 200, [
            'Content-Type' => 'image/jpeg',
        ]),
    ]);

    app(SafeImportHttpClient::class)->get('https://example.com/img.jpg', 50);
})->throws(ImportFetchException::class);

it('rejects response when content-length header exceeds max bytes', function () {
    Http::fake([
        'example.com/*' => Http::response('body', 200, [
            'Content-Type' => 'text/html',
            'Content-Length' => config('import.max_html_bytes') + 1,
        ]),
    ]);

    app(SafeImportHttpClient::class)->get('https://example.com/page');
})->throws(ImportFetchException::class);
