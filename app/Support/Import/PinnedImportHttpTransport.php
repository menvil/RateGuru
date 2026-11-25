<?php

namespace App\Support\Import;

use App\Exceptions\Import\ImportFetchException;
use App\Support\Observability\DomainLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The only class in this application that opens an outbound TCP connection
 * for a user-controlled import URL. Everything above this layer
 * (SafeImportHttpClient, the adapters, the Livewire form) only ever deals in
 * ResolvedImportTarget/ImportTransportResponse — never a raw URL string
 * handed to an HTTP client that could re-resolve it.
 *
 * The byte cap is enforced three ways, deliberately layered rather than
 * relying on one mechanism:
 *  - on_headers rejects immediately once an honest, oversized Content-Length
 *    is seen — before any body byte is read.
 *  - Guzzle's progress callback stops the transfer itself within a
 *    curl-buffer-sized margin of the cap, without waiting for the server to
 *    finish or to close the connection. This is the real defense against an
 *    oversized/adversarial response.
 *  - A final exact strlen() check on the completed body is what makes
 *    "exactly N bytes succeeds, N+1 fails" reliably testable, and is what
 *    actually catches a missing/lying-small/chunked Content-Length once the
 *    (still-bounded, thanks to the callback above) transfer completes.
 *
 * A custom sink stream object was deliberately not used here: Laravel's
 * Http::fake() stub handler special-cases the "sink" option by calling raw
 * fwrite($sink, $body) on it (see PendingRequest::sinkStubHandler()), which
 * requires a real PHP stream resource — an object implementing
 * Psr\Http\Message\StreamInterface fails there with a TypeError. Since this
 * transport must keep working under Http::fake() for the rest of the test
 * suite, the sink stays Guzzle's own default and the cap logic lives in the
 * three hooks above instead, none of which Http::fake() touches at all.
 */
final class PinnedImportHttpTransport implements ImportHttpTransport
{
    /** @var callable(): bool */
    private $curlAvailabilityCheck;

    /**
     * @param  (callable(): bool)|null  $curlAvailabilityCheck  Overridable only for tests; production always uses the real function_exists() check.
     */
    public function __construct(?callable $curlAvailabilityCheck = null)
    {
        $this->curlAvailabilityCheck = $curlAvailabilityCheck ?? static fn (): bool => function_exists('curl_init');
    }

    public function get(ResolvedImportTarget $target, ImportFetchPolicy $policy): ImportTransportResponse
    {
        return $this->fetch($target, $policy, null);
    }

    /**
     * The body goes to $path as it arrives (Guzzle's sink, given a path: Http::fake()
     * writes its stub body there too), under the same caps — the progress
     * callback still aborts past maxBytes, so a file never grows past it.
     */
    public function download(ResolvedImportTarget $target, ImportFetchPolicy $policy, string $path): ImportTransportResponse
    {
        return $this->fetch($target, $policy, $path);
    }

    private function fetch(ResolvedImportTarget $target, ImportFetchPolicy $policy, ?string $sink): ImportTransportResponse
    {
        if (! ($this->curlAvailabilityCheck)()) {
            // Without ext-curl, Guzzle's handler selection (Utils::
            // chooseHandler()) falls back to its pure-PHP StreamHandler,
            // which has no concept of the "curl" request option at all --
            // CURLOPT_RESOLVE (the pin this whole class exists for) would
            // be silently ignored and the stream context would perform its
            // own, uncontrolled DNS lookup of $target->host, silently
            // reopening the exact rebinding window this transport exists
            // to close. Failing closed here is safer than ever letting
            // that substitution happen invisibly.
            throw ImportFetchException::connectionError(
                $target->url,
                'The cURL extension is required for a pinned import fetch and is not available.',
            );
        }

        $contentLengthExceeded = false;
        $bodyCapExceeded = false;

        try {
            $response = Http::withOptions([
                // No transparent gzip/br decoding: the bytes counted below
                // are then exactly the bytes received on the wire, so a
                // small compressed payload can't expand past the cap during
                // a decode step the app never performs.
                'decode_content' => false,
                // Without this, Guzzle's own proxy resolution
                // (CurlFactory::resolveProxy()) falls back to reading
                // http_proxy/https_proxy/ALL_PROXY environment variables
                // the moment no "proxy" request option is set at all. Per
                // curl's own documentation, once a proxy is in play *the
                // proxy performs the destination's DNS resolution*, not
                // this machine — meaning CURLOPT_RESOLVE below would be
                // silently ineffective and every request would go through
                // an ambient, environment-configured proxy the pin can't
                // see or control, reopening the exact rebinding window
                // this whole class exists to close. An empty string is
                // Guzzle's own documented way to explicitly disable
                // proxying (including the environment-variable fallback)
                // for this request — CURLOPT_PROXY itself is a
                // Guzzle-reserved/deprecated raw option, not the right
                // tool here.
                'proxy' => '',
                'on_headers' => function ($guzzleResponse) use ($policy, &$contentLengthExceeded): void {
                    $contentLength = (int) $guzzleResponse->getHeaderLine('Content-Length');

                    if ($contentLength > 0 && $contentLength > $policy->maxBytes) {
                        $contentLengthExceeded = true;

                        throw new RuntimeException('Content-Length exceeds the configured cap.');
                    }
                },
                // Stops the transfer itself once the body passes the cap: a
                // progress callback that returns true makes curl abort on the
                // spot, so a server that keeps sending, or holds the
                // connection open, is never read to the end or to the
                // timeout. Guzzle refuses the raw curl progress options in
                // favour of this one, and composer.json requires the major
                // version of Guzzle whose callback can abort.
                'progress' => function (int $downloadTotal, int $downloaded) use ($policy, &$bodyCapExceeded): bool {
                    if ($downloaded > $policy->maxBytes) {
                        $bodyCapExceeded = true;

                        return true;
                    }

                    return false;
                },
                ...($sink !== null ? ['sink' => $sink] : []),
                'curl' => [
                    // Connects to the exact IP UrlImportValidator already
                    // resolved and validated for this hop, instead of
                    // letting curl perform its own uncontrolled DNS lookup
                    // of $target->host — this is the DNS rebinding/TOCTOU
                    // fix. The URL, Host header, and TLS SNI/certificate
                    // verification all still use $target->host untouched.
                    CURLOPT_RESOLVE => ["{$target->host}:{$target->port}:{$target->ip}"],
                    // A download that trickles — under 32 KB a second for 20
                    // seconds — is cut off rather than holding a worker for
                    // its whole time.
                    ...($sink !== null ? [CURLOPT_LOW_SPEED_LIMIT => 32 * 1024, CURLOPT_LOW_SPEED_TIME => 20] : []),
                ],
            ])
                ->timeout($policy->timeoutSeconds)
                ->connectTimeout($policy->connectTimeoutSeconds)
                ->withoutRedirecting()
                ->get($target->url);
        } catch (RequestException $e) {
            // A cap can also end a transfer whose response already said 4xx
            // or 5xx: Laravel then raises the response's own error instead of
            // a connection failure. The flags still say which it was.
            if ($contentLengthExceeded || $bodyCapExceeded) {
                throw ImportFetchException::responseTooLarge($target->url, $policy->maxBytes);
            }

            throw ImportFetchException::requestFailed($target->url, $e->response->status());
        } catch (ConnectionException $e) {
            if ($contentLengthExceeded || $bodyCapExceeded) {
                // Both caps end the transfer the way a network failure does:
                // a throw from on_headers and a transfer the progress callback
                // aborted both reject with the response received so far, and
                // for a 2xx response that surfaces as the same
                // ConnectionException a real network failure would produce.
                // The flags set above are what tell them apart.
                throw ImportFetchException::responseTooLarge($target->url, $policy->maxBytes);
            }

            // $e itself is never chained onward (see
            // ImportFetchException::connectionError()'s own docblock for
            // why) — whatever diagnostic value it has is captured here
            // instead, through structured logging that only ever carries
            // the host and the failing exception's class name, never the
            // URL, query string, or $e's own message.
            app(DomainLogger::class)->warning('url_import.connection_failed', [
                'source_host' => $target->host,
                'operation' => 'fetch',
                'exception_class' => $e::class,
            ]);

            throw ImportFetchException::connectionError($target->url, $e->getMessage());
        }

        $body = $sink === null ? $response->body() : '';
        $received = $sink === null ? strlen($body) : (int) @filesize($sink);

        if ($received > $policy->maxBytes) {
            throw ImportFetchException::responseTooLarge($target->url, $policy->maxBytes);
        }

        return new ImportTransportResponse(
            status: $response->status(),
            headers: $this->flattenHeaders($response->headers()),
            body: $body,
        );
    }

    /**
     * @param  array<string, list<string>>  $headers
     * @return array<string, string>
     */
    private function flattenHeaders(array $headers): array
    {
        $flat = [];

        foreach ($headers as $name => $values) {
            $flat[strtolower($name)] = $values[0] ?? '';
        }

        return $flat;
    }
}
