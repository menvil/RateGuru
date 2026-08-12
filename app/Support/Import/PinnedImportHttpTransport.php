<?php

namespace App\Support\Import;

use App\Exceptions\Import\ImportFetchException;
use App\Support\Observability\DomainLogger;
use Illuminate\Http\Client\ConnectionException;
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
 *  - progress rejects a genuinely large, actively-streaming body within a
 *    curl-buffer-sized margin of the cap, without waiting for it to finish
 *    (verified empirically: aborts a multi-GB unbounded stream in well under
 *    a second). This is the real defense against an oversized/adversarial
 *    response.
 *  - A final exact strlen() check on the completed body is what makes
 *    "exactly N bytes succeeds, N+1 fails" reliably testable, and is what
 *    actually catches a missing/lying-small/chunked Content-Length once the
 *    (still-bounded, thanks to the progress check above) transfer completes.
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
                'progress' => function (int $downloadTotal, int $downloaded) use ($policy): void {
                    if ($downloaded > $policy->maxBytes) {
                        // Thrown raw here reaches the catch below completely
                        // unwrapped — curl's progress callback, unlike
                        // on_headers, has no surrounding try/catch anywhere
                        // in Guzzle's own CurlFactory (verified empirically).
                        throw new StreamCapExceededSignal;
                    }
                },
                'curl' => [
                    // Connects to the exact IP UrlImportValidator already
                    // resolved and validated for this hop, instead of
                    // letting curl perform its own uncontrolled DNS lookup
                    // of $target->host — this is the DNS rebinding/TOCTOU
                    // fix. The URL, Host header, and TLS SNI/certificate
                    // verification all still use $target->host untouched.
                    CURLOPT_RESOLVE => ["{$target->host}:{$target->port}:{$target->ip}"],
                ],
            ])
                ->timeout($policy->timeoutSeconds)
                ->connectTimeout($policy->connectTimeoutSeconds)
                ->withoutRedirecting()
                ->get($target->url);
        } catch (StreamCapExceededSignal) {
            throw ImportFetchException::responseTooLarge($target->url, $policy->maxBytes);
        } catch (ConnectionException $e) {
            if ($contentLengthExceeded) {
                // on_headers aborts differently from progress: throwing from
                // inside it is caught by Guzzle's own CurlFactory and
                // re-wrapped into the same ConnectionException a real
                // network failure would produce. The flag set just above is
                // what tells the two apart.
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

        $body = $response->body();

        if (strlen($body) > $policy->maxBytes) {
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
