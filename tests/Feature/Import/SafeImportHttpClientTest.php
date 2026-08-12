<?php

use App\Exceptions\Import\ImportFetchException;
use App\Exceptions\Import\UnsafeImportUrlException;
use App\Support\Import\ImportHttpTransport;
use App\Support\Import\ImportTransportResponse;
use App\Support\Import\SafeImportHttpClient;

beforeEach(function () {
    bindFakeHostResolver([
        'good.example' => ['93.184.216.34'],
        'cdn.good.example' => ['93.184.216.35'],
        'private.example' => ['10.0.0.5'],
    ]);
});

it('fetches a url and returns status, headers, body, and the final url', function () {
    $transport = new ScriptedImportHttpTransport([
        new ImportTransportResponse(200, ['content-type' => 'text/html'], '<html></html>'),
    ]);
    app()->instance(ImportHttpTransport::class, $transport);

    $response = app(SafeImportHttpClient::class)->get('https://good.example/page');

    expect($response->status)->toBe(200)
        ->and($response->body)->toBe('<html></html>')
        ->and($response->header('content-type'))->toBe('text/html')
        ->and($response->finalUrl)->toBe('https://good.example/page');
});

it('rejects an unsafe url before ever calling the transport', function () {
    $transport = new ScriptedImportHttpTransport([]);
    app()->instance(ImportHttpTransport::class, $transport);

    expect(fn () => app(SafeImportHttpClient::class)->get('https://localhost/test'))
        ->toThrow(UnsafeImportUrlException::class);

    expect($transport->calls)->toBeEmpty();
});

it('propagates a transport-level fetch failure as ImportFetchException', function () {
    $transport = new ScriptedImportHttpTransport([
        function (): never {
            throw ImportFetchException::connectionError('https://good.example/page', 'simulated timeout');
        },
    ]);
    app()->instance(ImportHttpTransport::class, $transport);

    expect(fn () => app(SafeImportHttpClient::class)->get('https://good.example/page'))
        ->toThrow(ImportFetchException::class);
});

// --- Status code policy ---------------------------------------------------

it('rejects a 500 response', function () {
    $transport = new ScriptedImportHttpTransport([new ImportTransportResponse(500, [], '')]);
    app()->instance(ImportHttpTransport::class, $transport);

    expect(fn () => app(SafeImportHttpClient::class)->get('https://good.example/page'))
        ->toThrow(ImportFetchException::class);
});

it('rejects a 404 response', function () {
    $transport = new ScriptedImportHttpTransport([new ImportTransportResponse(404, [], '')]);
    app()->instance(ImportHttpTransport::class, $transport);

    expect(fn () => app(SafeImportHttpClient::class)->get('https://good.example/page'))
        ->toThrow(ImportFetchException::class);
});

it('rejects a 204 no-content response, since there is no body to work with', function () {
    $transport = new ScriptedImportHttpTransport([new ImportTransportResponse(204, [], '')]);
    app()->instance(ImportHttpTransport::class, $transport);

    expect(fn () => app(SafeImportHttpClient::class)->get('https://good.example/page'))
        ->toThrow(ImportFetchException::class);
});

it('rejects a 206 partial-content response, since this pipeline never sends Range and does not trust an unsolicited partial body', function () {
    $transport = new ScriptedImportHttpTransport([new ImportTransportResponse(206, [], 'partial')]);
    app()->instance(ImportHttpTransport::class, $transport);

    expect(fn () => app(SafeImportHttpClient::class)->get('https://good.example/page'))
        ->toThrow(ImportFetchException::class);
});

it('accepts a 201 created response', function () {
    $transport = new ScriptedImportHttpTransport([new ImportTransportResponse(201, [], 'created')]);
    app()->instance(ImportHttpTransport::class, $transport);

    $response = app(SafeImportHttpClient::class)->get('https://good.example/page');

    expect($response->status)->toBe(201);
});

// --- Redirect hardening ----------------------------------------------------

it('follows a public-to-public redirect and returns the final response', function () {
    $transport = new ScriptedImportHttpTransport([
        new ImportTransportResponse(302, ['location' => 'https://cdn.good.example/final'], ''),
        new ImportTransportResponse(200, [], 'final body'),
    ]);
    app()->instance(ImportHttpTransport::class, $transport);

    $response = app(SafeImportHttpClient::class)->get('https://good.example/start');

    expect($response->body)->toBe('final body')
        ->and($response->finalUrl)->toBe('https://cdn.good.example/final')
        ->and($transport->calls)->toHaveCount(2);
});

it('rejects a redirect to a private IPv4 address, without ever calling the transport for that hop', function () {
    $transport = new ScriptedImportHttpTransport([
        new ImportTransportResponse(302, ['location' => 'https://private.example/secret'], ''),
    ]);
    app()->instance(ImportHttpTransport::class, $transport);

    expect(fn () => app(SafeImportHttpClient::class)->get('https://good.example/page'))
        ->toThrow(UnsafeImportUrlException::class);

    // Only the first hop (the redirecting response itself) ever reached the
    // transport -- the redirect target was rejected before any connection
    // to it was attempted.
    expect($transport->calls)->toHaveCount(1);
});

it('rejects a redirect straight to a literal private IPv4 address', function () {
    $transport = new ScriptedImportHttpTransport([
        new ImportTransportResponse(302, ['location' => 'https://10.0.0.1/secret'], ''),
    ]);
    app()->instance(ImportHttpTransport::class, $transport);

    expect(fn () => app(SafeImportHttpClient::class)->get('https://good.example/page'))
        ->toThrow(UnsafeImportUrlException::class);
});

it('rejects a redirect to a private IPv6 address', function () {
    $transport = new ScriptedImportHttpTransport([
        new ImportTransportResponse(302, ['location' => 'https://[fc00::1]/secret'], ''),
    ]);
    app()->instance(ImportHttpTransport::class, $transport);

    expect(fn () => app(SafeImportHttpClient::class)->get('https://good.example/page'))
        ->toThrow(UnsafeImportUrlException::class);
});

it('rejects a redirect to the cloud metadata address', function () {
    $transport = new ScriptedImportHttpTransport([
        new ImportTransportResponse(302, ['location' => 'https://169.254.169.254/latest/meta-data'], ''),
    ]);
    app()->instance(ImportHttpTransport::class, $transport);

    expect(fn () => app(SafeImportHttpClient::class)->get('https://good.example/page'))
        ->toThrow(UnsafeImportUrlException::class);
});

it('rejects a redirect containing userinfo', function () {
    $transport = new ScriptedImportHttpTransport([
        new ImportTransportResponse(302, ['location' => 'https://user:pass@good.example/secret'], ''),
    ]);
    app()->instance(ImportHttpTransport::class, $transport);

    expect(fn () => app(SafeImportHttpClient::class)->get('https://good.example/page'))
        ->toThrow(UnsafeImportUrlException::class);
});

it('rejects a redirect to a forbidden port', function () {
    $transport = new ScriptedImportHttpTransport([
        new ImportTransportResponse(302, ['location' => 'https://good.example:8080/secret'], ''),
    ]);
    app()->instance(ImportHttpTransport::class, $transport);

    expect(fn () => app(SafeImportHttpClient::class)->get('https://good.example/page'))
        ->toThrow(UnsafeImportUrlException::class);
});

it('resolves a relative redirect (../) using RFC 3986, not the original request path', function () {
    $transport = new ScriptedImportHttpTransport([
        new ImportTransportResponse(302, ['location' => '../image.jpg'], ''),
        new ImportTransportResponse(200, [], 'ok'),
    ]);
    app()->instance(ImportHttpTransport::class, $transport);

    $response = app(SafeImportHttpClient::class)->get('https://good.example/a/b/page');

    expect($response->finalUrl)->toBe('https://good.example/a/image.jpg');
});

it('resolves a same-directory relative redirect (./)', function () {
    $transport = new ScriptedImportHttpTransport([
        new ImportTransportResponse(302, ['location' => './sibling'], ''),
        new ImportTransportResponse(200, [], 'ok'),
    ]);
    app()->instance(ImportHttpTransport::class, $transport);

    $response = app(SafeImportHttpClient::class)->get('https://good.example/a/b/page');

    expect($response->finalUrl)->toBe('https://good.example/a/b/sibling');
});

it('resolves a bare-query redirect (?x=1) against the same path', function () {
    $transport = new ScriptedImportHttpTransport([
        new ImportTransportResponse(302, ['location' => '?x=1'], ''),
        new ImportTransportResponse(200, [], 'ok'),
    ]);
    app()->instance(ImportHttpTransport::class, $transport);

    $response = app(SafeImportHttpClient::class)->get('https://good.example/a/b/page');

    expect($response->finalUrl)->toBe('https://good.example/a/b/page?x=1');
});

it('resolves a scheme-relative redirect (//host/path)', function () {
    $transport = new ScriptedImportHttpTransport([
        new ImportTransportResponse(302, ['location' => '//cdn.good.example/asset'], ''),
        new ImportTransportResponse(200, [], 'ok'),
    ]);
    app()->instance(ImportHttpTransport::class, $transport);

    $response = app(SafeImportHttpClient::class)->get('https://good.example/page');

    expect($response->finalUrl)->toBe('https://cdn.good.example/asset');
});

it('resolves an absolute-path redirect (/path)', function () {
    $transport = new ScriptedImportHttpTransport([
        new ImportTransportResponse(302, ['location' => '/elsewhere'], ''),
        new ImportTransportResponse(200, [], 'ok'),
    ]);
    app()->instance(ImportHttpTransport::class, $transport);

    $response = app(SafeImportHttpClient::class)->get('https://good.example/a/b/page');

    expect($response->finalUrl)->toBe('https://good.example/elsewhere');
});

it('follows a fully-qualified absolute redirect to a different host', function () {
    $transport = new ScriptedImportHttpTransport([
        new ImportTransportResponse(302, ['location' => 'https://cdn.good.example/path'], ''),
        new ImportTransportResponse(200, [], 'ok'),
    ]);
    app()->instance(ImportHttpTransport::class, $transport);

    $response = app(SafeImportHttpClient::class)->get('https://good.example/page');

    expect($response->finalUrl)->toBe('https://cdn.good.example/path');
});

it('strips the fragment from the redirect target before re-validating and fetching it', function () {
    $transport = new ScriptedImportHttpTransport([
        new ImportTransportResponse(302, ['location' => '/page#section'], ''),
        new ImportTransportResponse(200, [], 'ok'),
    ]);
    app()->instance(ImportHttpTransport::class, $transport);

    $response = app(SafeImportHttpClient::class)->get('https://good.example/start');

    expect($response->finalUrl)->toBe('https://good.example/page');
});

it('rejects a redirect with a missing Location header', function () {
    $transport = new ScriptedImportHttpTransport([
        new ImportTransportResponse(302, [], ''),
    ]);
    app()->instance(ImportHttpTransport::class, $transport);

    expect(fn () => app(SafeImportHttpClient::class)->get('https://good.example/page'))
        ->toThrow(ImportFetchException::class);
});

it('allows exactly the configured maximum number of redirects', function () {
    config(['import.max_redirects' => 3]);

    $transport = new ScriptedImportHttpTransport([
        new ImportTransportResponse(302, ['location' => '/hop1'], ''),
        new ImportTransportResponse(302, ['location' => '/hop2'], ''),
        new ImportTransportResponse(302, ['location' => '/hop3'], ''),
        new ImportTransportResponse(200, [], 'ok after exactly 3 redirects'),
    ]);
    app()->instance(ImportHttpTransport::class, $transport);

    $response = app(SafeImportHttpClient::class)->get('https://good.example/start');

    expect($response->body)->toBe('ok after exactly 3 redirects');
});

it('rejects one more redirect than the configured maximum', function () {
    config(['import.max_redirects' => 3]);

    $transport = new ScriptedImportHttpTransport([
        new ImportTransportResponse(302, ['location' => '/hop1'], ''),
        new ImportTransportResponse(302, ['location' => '/hop2'], ''),
        new ImportTransportResponse(302, ['location' => '/hop3'], ''),
        new ImportTransportResponse(302, ['location' => '/hop4'], ''),
    ]);
    app()->instance(ImportHttpTransport::class, $transport);

    expect(fn () => app(SafeImportHttpClient::class)->get('https://good.example/start'))
        ->toThrow(ImportFetchException::class);
});

it('rejects a redirect loop once it exceeds the configured maximum', function () {
    config(['import.max_redirects' => 2]);

    $transport = new ScriptedImportHttpTransport([
        new ImportTransportResponse(302, ['location' => 'https://good.example/loop'], ''),
        new ImportTransportResponse(302, ['location' => 'https://good.example/loop'], ''),
        new ImportTransportResponse(302, ['location' => 'https://good.example/loop'], ''),
    ]);
    app()->instance(ImportHttpTransport::class, $transport);

    expect(fn () => app(SafeImportHttpClient::class)->get('https://good.example/loop'))
        ->toThrow(ImportFetchException::class);
});

it('supports every 3xx redirect status this pipeline recognizes', function () {
    foreach ([301, 302, 303, 307, 308] as $status) {
        $transport = new ScriptedImportHttpTransport([
            new ImportTransportResponse($status, ['location' => '/final'], ''),
            new ImportTransportResponse(200, [], "ok-{$status}"),
        ]);
        app()->instance(ImportHttpTransport::class, $transport);

        $response = app(SafeImportHttpClient::class)->get('https://good.example/start');

        expect($response->body)->toBe("ok-{$status}");
    }
});

it('passes a custom maxBytes through to the transport policy instead of always using import.max_html_bytes', function () {
    $observedMaxBytes = null;

    $transport = new ScriptedImportHttpTransport([
        function ($target, $policy) use (&$observedMaxBytes): ImportTransportResponse {
            $observedMaxBytes = $policy->maxBytes;

            return new ImportTransportResponse(200, [], 'ok');
        },
    ]);
    app()->instance(ImportHttpTransport::class, $transport);

    app(SafeImportHttpClient::class)->get('https://good.example/img.jpg', 5);

    // Enforcing the cap is PinnedImportHttpTransport's job
    // (PinnedImportHttpTransportTest covers that directly) — this only
    // proves SafeImportHttpClient passes the custom limit through.
    expect($observedMaxBytes)->toBe(5);
});
