<?php

use App\Exceptions\Import\ImportFetchException;
use App\Support\Import\ImportFetchPolicy;
use App\Support\Import\ImportTransportResponse;
use App\Support\Import\PinnedImportHttpTransport;
use App\Support\Import\ResolvedImportTarget;
use Illuminate\Support\Facades\Http;

// ---------------------------------------------------------------------
// Part 1: request construction, verified via Http::fake()'s option-
// inspecting callback form (no real network — this only checks what
// PinnedImportHttpTransport asks Guzzle/curl to do).
// ---------------------------------------------------------------------

it('pins the connection via CURLOPT_RESOLVE to host:port:ip, never touches TLS verification, and disables transparent decompression', function () {
    $seenOptions = null;

    Http::fake(function ($request, $options) use (&$seenOptions) {
        $seenOptions = $options;

        return Http::response('ok', 200);
    });

    $transport = new PinnedImportHttpTransport;
    $target = new ResolvedImportTarget(url: 'https://example.com/page', scheme: 'https', host: 'example.com', port: 443, ip: '93.184.216.34');
    $transport->get($target, new ImportFetchPolicy(maxBytes: 1000, timeoutSeconds: 5, connectTimeoutSeconds: 2));

    expect($seenOptions['curl'][CURLOPT_RESOLVE])->toBe(['example.com:443:93.184.216.34'])
        ->and($seenOptions['decode_content'])->toBeFalse()
        // Never explicitly disabled anywhere -- Guzzle/Laravel's own default
        // (true) is what's in effect here.
        ->and($seenOptions['verify'] ?? true)->toBeTrue()
        ->and(isset($seenOptions['on_headers']))->toBeTrue()
        ->and(isset($seenOptions['progress']))->toBeTrue();
});

it('never sets CURLOPT_SSL_VERIFYPEER or CURLOPT_SSL_VERIFYHOST', function () {
    $seenOptions = null;

    Http::fake(function ($request, $options) use (&$seenOptions) {
        $seenOptions = $options;

        return Http::response('ok', 200);
    });

    $transport = new PinnedImportHttpTransport;
    $target = new ResolvedImportTarget(url: 'https://example.com/page', scheme: 'https', host: 'example.com', port: 443, ip: '93.184.216.34');
    $transport->get($target, new ImportFetchPolicy(maxBytes: 1000, timeoutSeconds: 5, connectTimeoutSeconds: 2));

    expect(array_key_exists(CURLOPT_SSL_VERIFYPEER, $seenOptions['curl'] ?? []))->toBeFalse()
        ->and(array_key_exists(CURLOPT_SSL_VERIFYHOST, $seenOptions['curl'] ?? []))->toBeFalse();
});

it('disables redirect-following at the transport level, since SafeImportHttpClient owns redirect handling itself', function () {
    $seenOptions = null;

    Http::fake(function ($request, $options) use (&$seenOptions) {
        $seenOptions = $options;

        return Http::response('', 302, ['Location' => 'https://elsewhere.example/']);
    });

    $transport = new PinnedImportHttpTransport;
    $target = new ResolvedImportTarget(url: 'https://example.com/page', scheme: 'https', host: 'example.com', port: 443, ip: '93.184.216.34');
    $response = $transport->get($target, new ImportFetchPolicy(maxBytes: 1000, timeoutSeconds: 5, connectTimeoutSeconds: 2));

    expect($seenOptions['allow_redirects'])->toBeFalse()
        ->and($response->status)->toBe(302);
});

// ---------------------------------------------------------------------
// Part 2: byte-cap enforcement via Http::fake() — exercises the final,
// exact strlen() check (the source of truth for anything that isn't a
// genuinely large streaming response, which Part 3 below covers for real).
// ---------------------------------------------------------------------

function fakeTransportResponse(string $body, array $headers = []): ImportTransportResponse
{
    Http::fake(['example.com/*' => Http::response($body, 200, $headers)]);

    $transport = new PinnedImportHttpTransport;
    $target = new ResolvedImportTarget(url: 'https://example.com/asset', scheme: 'https', host: 'example.com', port: 443, ip: '93.184.216.34');

    return $transport->get($target, new ImportFetchPolicy(maxBytes: 100, timeoutSeconds: 5, connectTimeoutSeconds: 2));
}

it('succeeds when Content-Length and the actual body are both below the cap', function () {
    $response = fakeTransportResponse(str_repeat('a', 50), ['Content-Length' => 50]);

    expect($response->body)->toHaveLength(50);
});

// An honest, oversized Content-Length with a small actual body can't be
// meaningfully tested through Http::fake(): its stub handler never invokes
// on_headers at all (only the "sink" option gets any special handling —
// see PinnedImportHttpTransport's own docblock), so that early-reject path
// only ever runs against a real transfer. Covered for real in Part 3 below
// ("rejects an honestly oversized Content-Length before the body is read").

it('succeeds with no Content-Length header at all when the body is within the cap', function () {
    $response = fakeTransportResponse(str_repeat('a', 50));

    expect($response->body)->toHaveLength(50);
});

it('succeeds at exactly the byte limit', function () {
    $response = fakeTransportResponse(str_repeat('a', 100));

    expect($response->body)->toHaveLength(100);
});

it('rejects at exactly one byte over the limit', function () {
    expect(fn () => fakeTransportResponse(str_repeat('a', 101)))
        ->toThrow(ImportFetchException::class);
});

it('rejects a lying Content-Length that understates the actual body size', function () {
    // Content-Length says 10 (within cap), the real body is 200 bytes --
    // the final exact check catches what the honest-Content-Length early
    // check structurally cannot.
    expect(fn () => fakeTransportResponse(str_repeat('a', 200), ['Content-Length' => 10]))
        ->toThrow(ImportFetchException::class);
});

it('rejects an oversized body carrying a malformed, non-numeric Content-Length header', function () {
    // (int) cast on a non-numeric string is 0, so this falls straight
    // through to the exact body-size check instead of crashing.
    expect(fn () => fakeTransportResponse(str_repeat('a', 200), ['Content-Length' => 'not-a-number']))
        ->toThrow(ImportFetchException::class);
});

it('succeeds for a within-cap body carrying a malformed, non-numeric Content-Length header', function () {
    $response = fakeTransportResponse(str_repeat('a', 50), ['Content-Length' => 'not-a-number']);

    expect($response->body)->toHaveLength(50);
});

it('rejects a chunked-style oversized body with no Content-Length header', function () {
    expect(fn () => fakeTransportResponse(str_repeat('a', 5000)))
        ->toThrow(ImportFetchException::class);
});

// ---------------------------------------------------------------------
// Part 3: real local-loopback HTTP server — the only way to genuinely
// exercise curl's own CURLOPT_RESOLVE pinning, on_headers, and progress-
// based mid-stream abort (all bypassed entirely by Http::fake(), see
// PinnedImportHttpTransport's own docblock). Never touches the real
// Internet or DNS: 127.0.0.1 only, and the pinned hostname doesn't exist
// in DNS at all — the request only succeeds because of the pin.
// ---------------------------------------------------------------------

/**
 * @return array{0: resource, 1: int, 2: string} process handle, port, docroot
 */
function startLoopbackFixtureServer(): array
{
    $docroot = sys_get_temp_dir().'/import-transport-fixture-'.uniqid('', true);
    mkdir($docroot, 0o755, true);

    file_put_contents($docroot.'/router.php', <<<'PHP'
<?php
$mode = $_GET['mode'] ?? 'echo';

switch ($mode) {
    case 'echo':
        header('Content-Type: text/plain');
        echo 'Host header seen: ' . ($_SERVER['HTTP_HOST'] ?? '(none)');
        break;

    case 'big-stream':
        header('Content-Type: application/octet-stream');
        $chunk = str_repeat('A', 65536);
        for ($i = 0; $i < 100000; $i++) { // ~6.5 GB if never aborted
            echo $chunk;
            if (connection_aborted()) {
                exit;
            }
            flush();
        }
        break;

    case 'oversized-content-length':
        header('Content-Length: 999999999');
        header('Content-Type: application/octet-stream');
        echo str_repeat('A', 1024);
        flush();
        sleep(20);
        break;

    case 'sleep-forever':
        sleep(30);
        echo 'done';
        break;

    default:
        http_response_code(404);
}
PHP);

    $port = random_int(20000, 60000);

    $process = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $docroot],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    if ($process === false) {
        throw new RuntimeException('Failed to start the loopback fixture server.');
    }

    // Give the built-in server a moment to bind before the first request.
    $deadline = microtime(true) + 3.0;

    while (microtime(true) < $deadline) {
        $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);

        if ($conn !== false) {
            fclose($conn);

            return [$process, $port, $docroot];
        }

        usleep(50_000);
    }

    throw new RuntimeException('Loopback fixture server never started listening.');
}

function stopLoopbackFixtureServer(array $server): void
{
    [$process, , $docroot] = $server;

    proc_terminate($process);
    proc_close($process);

    // Best-effort — this is a throwaway temp docroot, not user data.
    @unlink($docroot.'/router.php');
    @rmdir($docroot);
}

it('pins a genuinely unresolvable hostname to the loopback server and still sends the original Host header, real curl end to end', function () {
    $server = startLoopbackFixtureServer();
    [, $port] = $server;

    try {
        $transport = new PinnedImportHttpTransport;
        $target = new ResolvedImportTarget(
            url: "http://definitely-nonexistent-hostname.invalid:{$port}/router.php?mode=echo",
            scheme: 'http',
            host: 'definitely-nonexistent-hostname.invalid',
            port: $port,
            ip: '127.0.0.1',
        );

        $response = $transport->get($target, new ImportFetchPolicy(maxBytes: 10_000, timeoutSeconds: 5, connectTimeoutSeconds: 3));

        expect($response->status)->toBe(200)
            ->and($response->body)->toContain("Host header seen: definitely-nonexistent-hostname.invalid:{$port}");
    } finally {
        stopLoopbackFixtureServer($server);
    }
});

it('aborts a genuinely large streaming response well before it finishes, instead of buffering the whole thing', function () {
    $server = startLoopbackFixtureServer();
    [, $port] = $server;

    try {
        $transport = new PinnedImportHttpTransport;
        $target = new ResolvedImportTarget(url: "http://127.0.0.1:{$port}/router.php?mode=big-stream", scheme: 'http', host: '127.0.0.1', port: $port, ip: '127.0.0.1');

        $start = microtime(true);

        expect(fn () => $transport->get($target, new ImportFetchPolicy(maxBytes: 200_000, timeoutSeconds: 20, connectTimeoutSeconds: 3)))
            ->toThrow(ImportFetchException::class);

        $elapsed = microtime(true) - $start;

        // The fixture would take a long time to fully send ~6.5 GB; aborting
        // well under it (a few seconds, generously) is the whole point.
        expect($elapsed)->toBeLessThan(10.0);
    } finally {
        stopLoopbackFixtureServer($server);
    }
});

it('rejects an honestly oversized Content-Length before the body is read at all, not after a slow server finishes sending it', function () {
    $server = startLoopbackFixtureServer();
    [, $port] = $server;

    try {
        $transport = new PinnedImportHttpTransport;
        $target = new ResolvedImportTarget(url: "http://127.0.0.1:{$port}/router.php?mode=oversized-content-length", scheme: 'http', host: '127.0.0.1', port: $port, ip: '127.0.0.1');

        $start = microtime(true);

        expect(fn () => $transport->get($target, new ImportFetchPolicy(maxBytes: 500, timeoutSeconds: 25, connectTimeoutSeconds: 3)))
            ->toThrow(ImportFetchException::class);

        $elapsed = microtime(true) - $start;

        // The fixture sleeps 20s after sending its headers -- a genuine
        // early reject finishes in a fraction of a second, nowhere near that.
        expect($elapsed)->toBeLessThan(5.0);
    } finally {
        stopLoopbackFixtureServer($server);
    }
});

it('maps a real connect/read timeout to ImportFetchException, preserving the original exception', function () {
    $server = startLoopbackFixtureServer();
    [, $port] = $server;

    try {
        $transport = new PinnedImportHttpTransport;
        $target = new ResolvedImportTarget(url: "http://127.0.0.1:{$port}/router.php?mode=sleep-forever", scheme: 'http', host: '127.0.0.1', port: $port, ip: '127.0.0.1');

        $caught = null;

        try {
            $transport->get($target, new ImportFetchPolicy(maxBytes: 10_000, timeoutSeconds: 1, connectTimeoutSeconds: 1));
        } catch (ImportFetchException $e) {
            $caught = $e;
        }

        expect($caught)->not->toBeNull()
            ->and($caught->getPrevious())->not->toBeNull();
    } finally {
        stopLoopbackFixtureServer($server);
    }
});

it('maps a connection refused (nothing listening on the pinned IP/port) to ImportFetchException', function () {
    $transport = new PinnedImportHttpTransport;
    // An arbitrary high port on loopback that nothing is bound to.
    $target = new ResolvedImportTarget(url: 'http://127.0.0.1:65533/nope', scheme: 'http', host: '127.0.0.1', port: 65533, ip: '127.0.0.1');

    expect(fn () => $transport->get($target, new ImportFetchPolicy(maxBytes: 10_000, timeoutSeconds: 3, connectTimeoutSeconds: 2)))
        ->toThrow(ImportFetchException::class);
});
