<?php

namespace App\Support\Import;

use App\Exceptions\Import\ImportFetchException;
use App\Exceptions\Import\UnsafeImportUrlException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Throwable;

/**
 * The single application boundary for fetching a user-controlled import URL.
 * Every hop — the initial request and every redirect — is independently
 * resolved, validated, and pinned via UrlImportValidator before
 * ImportHttpTransport ever opens a connection for it. Nothing here (or
 * anywhere downstream) is allowed to hand a raw URL string to a generic HTTP
 * client instead.
 */
class SafeImportHttpClient
{
    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    public function __construct(
        private readonly UrlImportValidator $validator,
        private readonly ImportHttpTransport $transport,
    ) {}

    public function get(string $url, ?int $maxBytes = null): SafeImportResponse
    {
        $policy = new ImportFetchPolicy(
            maxBytes: $maxBytes ?? (int) config('import.max_html_bytes'),
            timeoutSeconds: (int) config('import.timeout_seconds'),
            connectTimeoutSeconds: (int) config('import.connect_timeout_seconds'),
        );

        $maxRedirects = (int) config('import.max_redirects');

        $target = $this->validator->validate($url);
        $hop = 0;

        while (true) {
            $response = $this->transport->get($target, $policy);

            if (! in_array($response->status, self::REDIRECT_STATUSES, true)) {
                break;
            }

            if ($hop >= $maxRedirects) {
                throw ImportFetchException::tooManyRedirects($url);
            }

            $location = $response->header('Location');

            if ($location === null || $location === '') {
                throw ImportFetchException::missingRedirectLocation($target->url);
            }

            $nextUrl = $this->resolveRedirectUrl($target->url, $location);
            $hop++;

            // Independently resolved, validated, and pinned for this exact
            // hop — a redirect to a private address is rejected here before
            // any connection to it is attempted, exactly like the initial
            // URL is.
            $target = $this->validator->validate($nextUrl, $hop);
        }

        $this->assertAcceptableStatus($response, $target->url);

        return new SafeImportResponse(
            status: $response->status,
            headers: $response->headers,
            body: $response->body,
            finalUrl: $target->url,
        );
    }

    private function resolveRedirectUrl(string $currentUrl, string $location): string
    {
        try {
            $resolved = UriResolver::resolve(new Uri($currentUrl), new Uri($location));
        } catch (Throwable) {
            throw UnsafeImportUrlException::invalidUrl($location);
        }

        // A fragment never travels to the server and has no business
        // surviving into a URL that gets re-validated and fetched.
        return (string) $resolved->withFragment('');
    }

    private function assertAcceptableStatus(ImportTransportResponse $response, string $url): void
    {
        // 204 has no body to work with; 206 is an unrequested, untrusted
        // partial response — this pipeline never sends Range and has no
        // support for reassembling partial content. Both are rejected
        // rather than silently treated as a normal 2xx.
        if ($response->status < 200 || $response->status >= 300 || $response->status === 204 || $response->status === 206) {
            throw ImportFetchException::requestFailed($url, $response->status);
        }
    }
}
