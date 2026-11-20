<?php

use App\Enums\TrafficPostbackOutcome;
use App\Services\Traffic\Postbacks\PostbackDeliverer;
use App\Support\Import\ResolvedImportTarget;
use App\Support\Import\UrlImportValidator;

// Real curl against a loopback server, the only way to see the options the
// deliverer gives the transfer actually work: Http::fake() never builds a curl
// handle, so it neither applies them nor refuses them.

/**
 * A deliverer whose every address is pinned to the loopback server: its own
 * validator never lets 127.0.0.1 or a port other than 80 and 443 through, so
 * a stand-in hands over the pin the real one would have checked.
 */
function loopbackPostbackDeliverer(int $port): PostbackDeliverer
{
    $validator = Mockery::mock(UrlImportValidator::class);
    $validator->shouldReceive('validateFor')->andReturnUsing(fn (string $url): ResolvedImportTarget => new ResolvedImportTarget(
        url: $url,
        scheme: 'http',
        host: (string) parse_url($url, PHP_URL_HOST),
        port: $port,
        ip: '127.0.0.1',
    ));

    return new PostbackDeliverer($validator);
}

it('connects to the IP checked for a host that has no DNS at all, and keeps what the network answered', function () {
    $server = startLoopbackFixtureServer();
    [, $port] = $server;

    try {
        $sent = loopbackPostbackDeliverer($port)->send([
            7 => ['url' => "http://postback-network.invalid:{$port}/router.php?mode=echo", 'rules' => null],
        ]);

        expect($sent[7]->outcome)->toBe(TrafficPostbackOutcome::Ok)
            ->and($sent[7]->httpStatus)->toBe(200)
            ->and($sent[7]->excerpt)->toContain("Host header seen: postback-network.invalid:{$port}");
    } finally {
        stopLoopbackFixtureServer($server);
    }
});

it('counts an answer that began and then stopped coming as a timeout, to be tried again, not as the network’s answer', function () {
    $server = startLoopbackFixtureServer();
    [, $port] = $server;

    try {
        // The status line and the headers come, then 2 of the 100 bytes promised, then nothing until the 8-second timeout.
        $sent = loopbackPostbackDeliverer($port)->send([
            7 => ['url' => "http://postback-network.invalid:{$port}/router.php?mode=stalled-short-answer", 'rules' => null],
        ]);

        expect($sent[7]->outcome)->toBe(TrafficPostbackOutcome::Timeout)
            ->and($sent[7]->httpStatus)->toBeNull()
            ->and($sent[7]->excerpt)->toBeNull();
    } finally {
        stopLoopbackFixtureServer($server);
    }
});

it('stops reading an answer past 16 KB at once, instead of waiting for the network to finish sending it', function () {
    $server = startLoopbackFixtureServer();
    [, $port] = $server;

    try {
        $start = microtime(true);
        $sent = loopbackPostbackDeliverer($port)->send([
            7 => ['url' => "http://postback-network.invalid:{$port}/router.php?mode=stalled-oversized-stream", 'rules' => null],
        ]);
        $elapsed = microtime(true) - $start;

        // The server sends far more than 16 KB at once and then holds the
        // connection for 20 seconds: read to the end, the postback would run
        // into its 8-second timeout instead of keeping the answer it got.
        expect($elapsed)->toBeLessThan(5.0)
            ->and($sent[7]->outcome)->toBe(TrafficPostbackOutcome::Ok)
            ->and($sent[7]->httpStatus)->toBe(200)
            ->and(strlen((string) $sent[7]->excerpt))->toBeLessThanOrEqual(PostbackDeliverer::MAX_BYTES);
    } finally {
        stopLoopbackFixtureServer($server);
    }
});
